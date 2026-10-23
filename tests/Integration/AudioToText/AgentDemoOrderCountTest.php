<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Infrastructure\DbDemoOrderCountReader;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function gmdate;
use function str_repeat;

/**
 * The "View (N)" count, and whose demo orders it may include.
 *
 * ## The defect this guards
 *
 * The count was `COUNT(*)` over the source order's demo orders with no actor condition at all, so an
 * agent saw "View (1)" against an order they had never completed. The row behind it was not even
 * another practitioner's — it was **Unmatched**, attributed to nobody. A link offering one person's
 * training record to whoever is looking is the invented attribution the importer refuses to make.
 *
 * Fixture ids sit in the `9876559xx` range and every row is removed again.
 */
final class AgentDemoOrderCountTest extends Unit
{
    private const STORE = 987655980;
    private const ORDER = 16800991;
    private const OTHER_ORDER = 16800992;

    private const AGENT_A = 7001;
    private const AGENT_B = 7002;

    private ConnectionInterface $connection;
    private DbDemoOrderCountReader $counts;

    private int $seq = 0;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->counts = new DbDemoOrderCountReader($this->connection);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    /** An agent with nothing of their own sees nothing, however much the order has produced. */
    public function testAnAgentSeesNoneOfAnotherPractitionersResults(): void
    {
        $admin = $this->seedAttempt('ADMIN', 42);
        $this->seedDemo($admin);

        self::assertSame(1, $this->countFor(null, null), 'management sees the order\'s whole history');
        self::assertSame(0, $this->countFor('AGENT', self::AGENT_A));
    }

    /** The one the live page got wrong: an unowned demo order belongs to nobody's count. */
    public function testAnUnmatchedDemoOrderCountsForNobody(): void
    {
        $this->seedDemo(null);

        self::assertSame(1, $this->countFor(null, null), 'management still sees it, to investigate');
        self::assertSame(0, $this->countFor('AGENT', self::AGENT_A));
        self::assertSame(0, $this->countFor('ADMIN', 42));
    }

    public function testAnAgentSeesTheirOwnSingleResult(): void
    {
        $this->seedDemo($this->seedAttempt('AGENT', self::AGENT_A));

        self::assertSame(1, $this->countFor('AGENT', self::AGENT_A));
    }

    /**
     * Three results, three counted — including two from one attempt.
     *
     * Separate demo orders are separate work even when one practice session produced them, so they
     * are not folded together.
     */
    public function testEveryDistinctResultOfTheSameAgentIsCounted(): void
    {
        $first = $this->seedAttempt('AGENT', self::AGENT_A);
        $second = $this->seedAttempt('AGENT', self::AGENT_A);

        $this->seedDemo($first);
        $this->seedDemo($first);
        $this->seedDemo($second);

        self::assertSame(3, $this->countFor('AGENT', self::AGENT_A));
    }

    /** One agent's work never swells another's count. */
    public function testOneAgentsResultsNeverCountForAnother(): void
    {
        $this->seedDemo($this->seedAttempt('AGENT', self::AGENT_B));
        $this->seedDemo($this->seedAttempt('AGENT', self::AGENT_B));

        self::assertSame(2, $this->countFor('AGENT', self::AGENT_B));
        self::assertSame(0, $this->countFor('AGENT', self::AGENT_A));
    }

    /**
     * The realm is part of the filter, not just the id.
     *
     * The three realms number their users separately, so `ADMIN 7001` and `AGENT 7001` are different
     * people. Matching on the id alone would hand one the other's record.
     */
    public function testTheRealmIsPartOfTheFilter(): void
    {
        $this->seedDemo($this->seedAttempt('ADMIN', self::AGENT_A));

        self::assertSame(1, $this->countFor('ADMIN', self::AGENT_A));
        self::assertSame(0, $this->countFor('AGENT', self::AGENT_A));
    }

    /** An attempt that produced nothing adds nothing — a click is not a result. */
    public function testAnAttemptWithNoDemoOrderCountsNothing(): void
    {
        $this->seedAttempt('AGENT', self::AGENT_A);

        self::assertSame(0, $this->countFor('AGENT', self::AGENT_A));
    }

    /** And the count stays bound to one source order. */
    public function testTheCountIsScopedToItsOwnSourceOrder(): void
    {
        $this->seedDemo($this->seedAttempt('AGENT', self::AGENT_A), self::OTHER_ORDER);

        self::assertSame(0, $this->countFor('AGENT', self::AGENT_A));
        self::assertSame(1, $this->countFor('AGENT', self::AGENT_A, self::OTHER_ORDER));
    }

    private function countFor(?string $realm, ?int $id, int $sourceOrderId = self::ORDER): int
    {
        $counts = $this->counts->countsFor(self::STORE, [(string) $sourceOrderId], $realm, $id);

        return $counts[(string) $sourceOrderId] ?? 0;
    }

    private function seedAttempt(string $realm, int $actorId): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order_testing_attempts}}', [
            'public_id' => str_pad((string) (++$this->seq), 32, 'e', STR_PAD_LEFT),
            'store_source_id' => self::STORE,
            'source_order_id' => self::ORDER,
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

    private function seedDemo(?int $attemptId, int $sourceOrderId = self::ORDER): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order_testing_demo_orders}}', [
            'public_id' => str_pad((string) (++$this->seq), 32, 'f', STR_PAD_LEFT),
            'store_source_id' => self::STORE,
            'source_order_id' => $sourceOrderId,
            'demo_order_id' => 16630000 + $this->seq,
            'matched_attempt_id' => $attemptId,
            'source_filename' => 'fixture.json',
            'content_hash' => str_repeat('0', 64),
            'raw_payload' => '{}',
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%order_testing_demo_orders}}', ['store_source_id' => self::STORE])->execute();
        $this->connection->createCommand()
            ->delete('{{%order_testing_attempts}}', ['store_source_id' => self::STORE])->execute();
    }
}
