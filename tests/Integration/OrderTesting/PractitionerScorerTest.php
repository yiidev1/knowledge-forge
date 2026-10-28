<?php

declare(strict_types=1);

namespace App\Tests\Integration\OrderTesting;

use App\OrderTesting\Application\Comparison\ComparisonScorer;
use App\OrderTesting\Application\Comparison\DemoOrderNormalizer;
use App\OrderTesting\Application\Comparison\OrderComparator;
use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Application\Practice\PractitionerScorer;
use App\OrderTesting\Application\Practice\PractitionerTry;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\Practice\PracticeResultsQuery;
use App\OrderTesting\Infrastructure\DbOriginalItemsReader;
use App\OrderTesting\Infrastructure\DbPracticeResultsReader;
use App\OrderTesting\Infrastructure\DbSourceOrderReader;
use App\Shared\Application\Time\AppTimeZone;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_map;
use function gmdate;
use function json_encode;
use function str_pad;
use function str_repeat;

/**
 * Best and latest score for a page of practitioners, and what happens when one of them has hundreds.
 *
 * ## The question this answers
 *
 * A score is derived, not stored: every one costs a JSON parse and a full document comparison in
 * PHP. "Best score" therefore means scoring everything that practitioner ever produced, which is
 * cheap at four results and not cheap at four hundred.
 *
 * So the page spends a fixed budget. These tests pin what that buys and — more importantly — what it
 * refuses to do: a Best it could not compute exactly is reported as **unknown**, never as the best
 * of whichever subset happened to fit. A figure somebody judges a person by has to mean one thing.
 *
 * Fixture ids sit in the `9876559xx` range and every row is removed again.
 */
final class PractitionerScorerTest extends Unit
{
    private const STORE = 987655983;
    private const ORDER = 16800996;

    private ConnectionInterface $connection;
    private DbPracticeResultsReader $reader;
    private PractitionerScorer $scorer;

    private int $seq = 0;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $normalizer = new OrderNormalizer();
        $this->reader = new DbPracticeResultsReader($this->connection, new AppTimeZone());
        $this->scorer = new PractitionerScorer(
            $this->reader,
            new DbSourceOrderReader($this->connection, $normalizer),
            new DbOriginalItemsReader($this->connection),
            new DemoOrderNormalizer($normalizer),
            new OrderComparator(),
            new ComparisonScorer(),
        );
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    /** The ordinary case: a handful of tries, and every figure exact. */
    public function testASmallHistoryIsScoredExactly(): void
    {
        $attempt = $this->seedAttempt(7601);

        foreach ([100, 200, 300, 400] as $offset) {
            $this->seedDemo($attempt, 1_700_000_000 + $offset);
        }

        $scores = $this->scoreThePage();
        $row = $scores['AGENT:7601'];

        self::assertTrue($row->bestIsExact, 'four tries must be well within budget');
        self::assertNotNull($row->latest);
    }

    /**
     * **The case the client asked about.** One practitioner with far more results than the cap.
     *
     * Latest is still exact, because it is one comparison however long the history. Best is refused
     * rather than approximated — and `bestIsExact` is false, which is what the table reads to say so
     * and to point at the drawer instead.
     */
    public function testAHugeHistoryKeepsLatestExactAndRefusesToGuessBest(): void
    {
        $attempt = $this->seedAttempt(7602);

        for ($i = 0; $i < PractitionerScorer::PER_PRACTITIONER + 20; ++$i) {
            $this->seedDemo($attempt, 1_700_000_000 + $i);
        }

        $scores = $this->scoreThePage();
        $row = $scores['AGENT:7602'];

        self::assertNotNull($row->latest, 'the latest score is one comparison and is always affordable');
        self::assertFalse($row->bestIsExact);
        self::assertNull($row->best, 'a Best that could not be computed exactly must not be reported');
    }

    /**
     * And one outlier does not cost the rows below it their own Best.
     *
     * The per-practitioner cap exists for this: without it the first row would spend the whole
     * budget and every practitioner after it would show an unknown Best, which would make the table
     * read as broken rather than as bounded.
     */
    public function testAnOutlierDoesNotStarveTheRestOfThePage(): void
    {
        // The outlier first, so it is scored first and would eat the budget if nothing stopped it.
        $huge = $this->seedAttempt(7603);

        for ($i = 0; $i < PractitionerScorer::PER_PRACTITIONER + 50; ++$i) {
            $this->seedDemo($huge, 1_900_000_000 + $i);
        }

        foreach ([7604, 7605, 7606] as $actor) {
            $attempt = $this->seedAttempt($actor);
            $this->seedDemo($attempt, 1_700_000_000 + $actor);
            $this->seedDemo($attempt, 1_700_001_000 + $actor);
        }

        $scores = $this->scoreThePage();

        self::assertFalse($scores['AGENT:7603']->bestIsExact, 'the outlier is the one that is bounded');

        foreach ([7604, 7605, 7606] as $actor) {
            self::assertTrue(
                $scores['AGENT:' . $actor]->bestIsExact,
                'practitioner ' . $actor . ' lost their Best to somebody else\'s history',
            );
        }
    }

    /** A comparison that cannot be made is not a zero, and cannot be anybody's best. */
    public function testAnUnavailableComparisonIsNeverTreatedAsZero(): void
    {
        // No mirrored source order is seeded, so there is nothing to compare against.
        $this->seedDemo($this->seedAttempt(7607), 1_700_000_100);

        $row = $this->scoreThePage()['AGENT:7607'];

        self::assertNull($row->best, 'an unavailable comparison must not become a best score');
    }

    /**
     * Every try is listed for the row, oldest first, numbered exactly as the drawer numbers them.
     *
     * Inserted out of order, so the list has to follow when each result was created rather than when
     * it happened to be imported — which is what "Try 2" means everywhere else.
     */
    public function testEveryTryIsListedOldestFirstWithTheDrawersNumbers(): void
    {
        $attempt = $this->seedAttempt(7608);
        $second = $this->seedDemo($attempt, 1_700_000_200);
        $first = $this->seedDemo($attempt, 1_700_000_100);
        $third = $this->seedDemo($attempt, 1_700_000_300);

        $row = $this->scoreThePage()['AGENT:7608'];

        self::assertSame([$first, $second, $third], array_map(
            static fn(PractitionerTry $try): int => $try->demo->demoOrderId,
            $row->tries,
        ));
        self::assertSame([1, 2, 3], array_map(static fn(PractitionerTry $try): int => $try->number, $row->tries));

        // The drawer's own numbering, asked for directly.
        $numbers = $this->reader->tryNumbersFor(self::STORE, self::ORDER, InitiatorType::Agent, 7608);
        foreach ($row->tries as $try) {
            self::assertSame($numbers[$try->demo->demoOrderId], $try->number);
        }

        // The latest is the last one listed, and it is the score the row has always called Latest.
        self::assertSame($third, $row->latestTry()?->demo->demoOrderId);
        self::assertSame($row->latest?->overall, $row->latestTry()?->score->overall);
        self::assertSame(0, $row->earlierTriesNotListed(3));
    }

    /**
     * Too long a history for one page: its latest try is listed, and the rest are counted — never a
     * partial list passing for the whole of it.
     */
    public function testAHugeHistoryListsItsLatestTryAndCountsTheRest(): void
    {
        $attempt = $this->seedAttempt(7609);
        $total = PractitionerScorer::PER_PRACTITIONER + 5;
        $last = 0;

        for ($i = 0; $i < $total; ++$i) {
            $last = $this->seedDemo($attempt, 1_700_000_000 + $i);
        }

        $row = $this->scoreThePage()['AGENT:7609'];

        self::assertCount(1, $row->tries);
        self::assertSame($last, $row->latestTry()?->demo->demoOrderId);
        self::assertSame($total, $row->latestTry()?->number, 'the latest is numbered over the whole history');
        self::assertSame($total - 1, $row->earlierTriesNotListed($total));
    }

    /** @return array<string, \App\OrderTesting\Application\Practice\PractitionerScores> */
    private function scoreThePage(): array
    {
        $page = $this->reader->practitioners(
            self::STORE,
            self::ORDER,
            PracticeResultsQuery::none(),
            25,
            0,
        );

        return $this->scorer->forPage(self::STORE, self::ORDER, $page);
    }

    private function seedAttempt(int $actorId): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order_testing_attempts}}', [
            'public_id' => str_pad((string) (++$this->seq), 32, 'd', STR_PAD_LEFT),
            'store_source_id' => self::STORE,
            'source_order_id' => self::ORDER,
            'initiated_by_type' => 'AGENT',
            'initiated_by_id' => $actorId,
            'status' => 'EXPIRED',
            'started_at' => $now,
            'expires_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    /** @return int the demo order id it wrote */
    private function seedDemo(int $attemptId, int $createdAt): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $demoOrderId = 16_900_000 + ++$this->seq;

        $this->connection->createCommand()->insert('{{%order_testing_demo_orders}}', [
            'public_id' => str_pad((string) $this->seq, 32, 'e', STR_PAD_LEFT),
            'store_source_id' => self::STORE,
            'source_order_id' => self::ORDER,
            'demo_order_id' => $demoOrderId,
            'matched_attempt_id' => $attemptId,
            'order_created_at' => $createdAt,
            'total_amount' => '12.50000',
            'source_filename' => 'fixture.json',
            'content_hash' => str_repeat('3', 64),
            // A real document, so normalising and comparing it costs what it costs in production.
            'raw_payload' => (string) json_encode([
                'id' => $demoOrderId,
                'total_amount' => '12.50000',
                'type' => 'take-out',
                'payment_method' => 'cash',
            ]),
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
    }
}
