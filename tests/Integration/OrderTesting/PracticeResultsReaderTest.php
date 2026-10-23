<?php

declare(strict_types=1);

namespace App\Tests\Integration\OrderTesting;

use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\Practice\PracticeResultsQuery;
use App\OrderTesting\Infrastructure\DbPracticeResultsReader;
use App\Shared\Application\Time\AppTimeZone;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function gmdate;
use function str_pad;
use function str_repeat;

/**
 * The Practice Results read side, against real MySQL and at the scale the client described.
 *
 * ## What is actually being guarded
 *
 * The page this serves used to read every attempt and every demo order — payloads included — to draw
 * one screen. These tests pin the three properties that replaced it: the aggregate is one query, it
 * is scoped to one source order, and nothing unbounded is read to produce a row.
 *
 * The hundred-practitioner case is here rather than described, because "it should scale" is not a
 * property anybody can check by reading the SQL.
 *
 * Fixture ids sit in the `9876559xx` range and every row is removed again.
 */
final class PracticeResultsReaderTest extends Unit
{
    private const STORE = 987655982;
    private const ORDER = 16800994;
    private const OTHER_ORDER = 16800995;

    private ConnectionInterface $connection;
    private DbPracticeResultsReader $reader;

    private int $seq = 0;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();
        $this->reader = new DbPracticeResultsReader($this->connection, new AppTimeZone());
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    /** A practitioner appears once, with their results counted and their last practice named. */
    public function testOnePractitionerWithOneResult(): void
    {
        $attempt = $this->seedAttempt('AGENT', 7301);
        $this->seedDemo($attempt, 1_700_000_100);

        $rows = $this->reader->practitioners(self::STORE, self::ORDER, PracticeResultsQuery::none(), 25, 0);

        self::assertCount(1, $rows);
        self::assertSame(InitiatorType::Agent, $rows[0]->realm);
        self::assertSame(7301, $rows[0]->actorId);
        self::assertSame(1, $rows[0]->completedTries);
        self::assertSame(1, $this->reader->countPractitioners(self::STORE, self::ORDER, PracticeResultsQuery::none()));
    }

    /** Four results, one practitioner, one row — not four. */
    public function testManyResultsCollapseIntoOneRow(): void
    {
        $attempt = $this->seedAttempt('AGENT', 7302);

        foreach ([100, 200, 300, 400] as $offset) {
            $this->seedDemo($attempt, 1_700_000_000 + $offset);
        }

        $rows = $this->reader->practitioners(self::STORE, self::ORDER, PracticeResultsQuery::none(), 25, 0);

        self::assertCount(1, $rows);
        self::assertSame(4, $rows[0]->completedTries);
        self::assertSame(4, $this->reader->countResultsFor(self::STORE, self::ORDER, InitiatorType::Agent, 7302));
    }

    /**
     * Several demo orders under ONE attempt are several results.
     *
     * The data model allows it — a practice session that produced two orders — and merging them
     * would quietly lose one somebody actually created.
     */
    public function testTwoResultsOfOneAttemptAreBothCounted(): void
    {
        $attempt = $this->seedAttempt('AGENT', 7303);
        $this->seedDemo($attempt, 1_700_000_100);
        $this->seedDemo($attempt, 1_700_000_200);

        $rows = $this->reader->practitioners(self::STORE, self::ORDER, PracticeResultsQuery::none(), 25, 0);

        self::assertSame(2, $rows[0]->completedTries);
    }

    /** An attempt that produced nothing is not a practitioner: a click is not a result. */
    public function testAnAttemptWithNoResultIsNotAPractitioner(): void
    {
        $this->seedAttempt('AGENT', 7304);

        self::assertSame([], $this->reader->practitioners(self::STORE, self::ORDER, PracticeResultsQuery::none(), 25, 0));
        self::assertSame(0, $this->reader->countPractitioners(self::STORE, self::ORDER, PracticeResultsQuery::none()));
    }

    /** Unmatched results belong to nobody, and are counted on their own. */
    public function testUnmatchedResultsReachNoPractitioner(): void
    {
        $this->seedDemo(null, 1_700_000_100);
        $this->seedDemo(null, 1_700_000_200);

        self::assertSame([], $this->reader->practitioners(self::STORE, self::ORDER, PracticeResultsQuery::none(), 25, 0));
        self::assertSame(2, $this->reader->unmatchedCount(self::STORE, self::ORDER));
        self::assertSame(2, $this->reader->resultCount(self::STORE, self::ORDER));
        self::assertCount(2, $this->reader->unmatched(self::STORE, self::ORDER, 25, 0));
    }

    /**
     * The realm is half the identity.
     *
     * `admin_users.id = 1` and an Order58 `admin_id = 1` are two different people in this
     * installation. Grouping by the id alone would merge them into one row.
     */
    public function testTwoRealmsSharingOneIdAreTwoPractitioners(): void
    {
        $this->seedDemo($this->seedAttempt('ADMIN', 7305), 1_700_000_100);
        $this->seedDemo($this->seedAttempt('AGENT', 7305), 1_700_000_200);

        $rows = $this->reader->practitioners(self::STORE, self::ORDER, PracticeResultsQuery::none(), 25, 0);

        self::assertCount(2, $rows);
        self::assertSame(2, $this->reader->countPractitioners(self::STORE, self::ORDER, PracticeResultsQuery::none()));
    }

    /** And another source order's results never reach this one's table. */
    public function testAnotherSourceOrderIsNotCounted(): void
    {
        $this->seedDemo($this->seedAttempt('AGENT', 7306, self::OTHER_ORDER), 1_700_000_100, self::OTHER_ORDER);

        self::assertSame([], $this->reader->practitioners(self::STORE, self::ORDER, PracticeResultsQuery::none(), 25, 0));
    }

    /** Try numbers run from the oldest result and do not depend on how the page is sorted. */
    public function testTryNumbersAreOldestFirstAndStable(): void
    {
        $attempt = $this->seedAttempt('AGENT', 7307);
        $first = $this->seedDemo($attempt, 1_700_000_100);
        $second = $this->seedDemo($attempt, 1_700_000_200);
        $third = $this->seedDemo($attempt, 1_700_000_300);

        $numbers = $this->reader->tryNumbersFor(self::STORE, self::ORDER, InitiatorType::Agent, 7307);

        self::assertSame(1, $numbers[$first]);
        self::assertSame(2, $numbers[$second]);
        self::assertSame(3, $numbers[$third]);

        // And the page itself reads newest first, which is the opposite order on purpose.
        $page = $this->reader->resultsFor(self::STORE, self::ORDER, InitiatorType::Agent, 7307, 25, 0);
        self::assertSame($third, $page[0]->demoOrderId);
    }

    /**
     * A hundred practitioners, and a page is still twenty-five rows.
     *
     * The case the client asked about. What it proves is that the table's cost is set by the page
     * size and not by how many people have practised: the same twenty-five rows come back whether
     * there are thirty practitioners or three hundred.
     */
    public function testAHundredPractitionersPaginateTwentyFiveAtATime(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            $attempt = $this->seedAttempt('AGENT', 7400 + $i);

            for ($t = 0; $t < 3; ++$t) {
                $this->seedDemo($attempt, 1_700_000_000 + ($i * 1000) + $t);
            }
        }

        $query = PracticeResultsQuery::none();

        self::assertSame(100, $this->reader->countPractitioners(self::STORE, self::ORDER, $query));
        self::assertSame(300, $this->reader->resultCount(self::STORE, self::ORDER));

        $first = $this->reader->practitioners(self::STORE, self::ORDER, $query, 25, 0);
        $second = $this->reader->practitioners(self::STORE, self::ORDER, $query, 25, 25);
        $last = $this->reader->practitioners(self::STORE, self::ORDER, $query, 25, 75);

        self::assertCount(25, $first);
        self::assertCount(25, $second);
        self::assertCount(25, $last);

        // No practitioner appears on two pages, which is what the total ordering is for.
        $keys = [];

        foreach ([$first, $second, $last] as $page) {
            foreach ($page as $row) {
                self::assertArrayNotHasKey($row->key(), $keys, 'a practitioner appeared on two pages');
                $keys[$row->key()] = true;
            }
        }

        self::assertCount(75, $keys);
    }

    /** Searching narrows in the database, by a fragment of the name. */
    public function testSearchingNarrowsByName(): void
    {
        $this->seedAgent(7501, 'rosalind');
        $this->seedAgent(7502, 'rupert');
        $this->seedDemo($this->seedAttempt('AGENT', 7501), 1_700_000_100);
        $this->seedDemo($this->seedAttempt('AGENT', 7502), 1_700_000_200);

        $all = PracticeResultsQuery::none();
        self::assertSame(2, $this->reader->countPractitioners(self::STORE, self::ORDER, $all));

        $rosa = PracticeResultsQuery::fromParams(['agent' => 'rosa']);
        $rows = $this->reader->practitioners(self::STORE, self::ORDER, $rosa, 25, 0);

        self::assertCount(1, $rows);
        self::assertSame('rosalind', $rows[0]->name);
        self::assertSame(1, $this->reader->countPractitioners(self::STORE, self::ORDER, $rosa));
    }

    /** A wildcard typed by a reader is a character, not a pattern. */
    public function testSearchTreatsWildcardsAsText(): void
    {
        $this->seedAgent(7503, 'ab');
        $this->seedDemo($this->seedAttempt('AGENT', 7503), 1_700_000_100);

        $query = PracticeResultsQuery::fromParams(['agent' => 'a_b']);

        self::assertSame(0, $this->reader->countPractitioners(self::STORE, self::ORDER, $query));
    }

    private function visibleName(int $adminId): ?string
    {
        $rows = $this->reader->practitioners(self::STORE, self::ORDER, PracticeResultsQuery::none(), 25, 0);

        foreach ($rows as $row) {
            if ($row->actorId === $adminId) {
                return $row->name;
            }
        }

        return null;
    }

    private function seedAgent(int $adminId, string $username): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_agents}}', [
            'admin_id' => $adminId,
            'username' => $username,
            'status' => 'active',
            'user_type' => 'agent',
            'sync_hash' => str_repeat('a', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedAttempt(string $realm, int $actorId, int $sourceOrderId = self::ORDER): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order_testing_attempts}}', [
            'public_id' => str_pad((string) (++$this->seq), 32, 'b', STR_PAD_LEFT),
            'store_source_id' => self::STORE,
            'source_order_id' => $sourceOrderId,
            'initiated_by_type' => $realm,
            'initiated_by_id' => $actorId,
            'status' => 'EXPIRED',
            'started_at' => $now,
            'expires_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    /** @return int the demo order id */
    private function seedDemo(?int $attemptId, int $createdAt, int $sourceOrderId = self::ORDER): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $demoOrderId = 16_700_000 + ++$this->seq;

        $this->connection->createCommand()->insert('{{%order_testing_demo_orders}}', [
            'public_id' => str_pad((string) $this->seq, 32, 'c', STR_PAD_LEFT),
            'store_source_id' => self::STORE,
            'source_order_id' => $sourceOrderId,
            'demo_order_id' => $demoOrderId,
            'matched_attempt_id' => $attemptId,
            'order_created_at' => $createdAt,
            'source_filename' => 'fixture.json',
            'content_hash' => str_repeat('2', 64),
            'raw_payload' => '{}',
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return $demoOrderId;
    }

    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%order_testing_demo_orders}}', ['store_source_id' => self::STORE])->execute();
        $this->connection->createCommand()
            ->delete('{{%order_testing_attempts}}', ['store_source_id' => self::STORE])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_agents}}', ['admin_id' => [7501, 7502, 7503]])->execute();
    }
}
