<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Infrastructure\DbItemSyncSummaryReader;
use App\Shared\Infrastructure\Db\DatabaseTarget;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function gmdate;
use function str_repeat;

/**
 * The progress figures above the Order58 orders listing, against real MySQL.
 *
 * ## What is really being guarded
 *
 * The **scope**, which is the part a number like "4 of 294" lives or dies by. This counts every
 * mirrored order in the listing's store filter — one store when filtered, all stores when not — which
 * is the same set the table underneath is drawn from.
 *
 * It must NOT follow the date, order-id or status filters. Those pick which rows appear; the bar
 * answers how much of the store's order history has its items. A bar that silently narrowed with the
 * date range would read as progress going backwards whenever somebody filtered.
 *
 * And the single-query promise, because this runs on every view of a paginated page.
 */
final class ItemSyncSummaryTest extends Unit
{
    private const STORE = 987655980;
    private const OTHER_STORE = 987655981;

    private ConnectionInterface $connection;
    private DbItemSyncSummaryReader $reader;
    private bool $ready = false;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        DatabaseTarget::resolve($this->connection)->assertLocalForSchemaChange();

        $exists = (int) $this->connection->createCommand(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order58_orders'
             AND COLUMN_NAME = 'items_status'",
        )->queryScalar();

        if ($exists === 0) {
            $this->markTestSkipped('items_status does not exist — the migration has not been applied.');
        }

        $this->ready = true;
        $this->reader = new DbItemSyncSummaryReader($this->connection);
        $this->cleanup();
    }

    protected function _after(): void
    {
        if ($this->ready) {
            $this->cleanup();
        }
    }

    // ------------------------------------------------------------------ the scope

    /**
     * Every mirrored order counts — not only the ones somebody is testing.
     *
     * This is the scope change this page exists for. The earlier store-page version counted only
     * orders with a demo order, which answered a different and much narrower question.
     */
    public function testEveryStoredOrderOfTheStoreIsCounted(): void
    {
        $this->seedOrder(1, 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedOrder(2, 'PENDING');
        $this->seedOrder(3, 'PENDING');

        $summary = $this->reader->summaryFor(self::STORE);

        $this->assertSame(3, $summary->total, 'An order with no demo order still counts.');
        $this->assertSame(1, $summary->ready);
        $this->assertSame(2, $summary->notSynced);
    }

    /** One store's figures can never include another's. */
    public function testAStoreFilterExcludesOtherStores(): void
    {
        $this->seedOrder(1, 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedOrder(1, 'FAILED', store: self::OTHER_STORE);
        $this->seedOrder(2, 'FAILED', store: self::OTHER_STORE);

        $summary = $this->reader->summaryFor(self::STORE);

        $this->assertSame(1, $summary->total);
        $this->assertSame(0, $summary->failed);
    }

    /** No filter means every stored order, which is what the unfiltered listing shows. */
    public function testWithoutAStoreFilterEveryStoredOrderIsCounted(): void
    {
        $this->seedOrder(1, 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedOrder(1, 'PENDING', store: self::OTHER_STORE);

        $summary = $this->reader->summaryFor(null);

        // The database may hold other rows; what matters is that both fixture stores are included.
        $this->assertGreaterThanOrEqual(2, $summary->total);
        $this->assertGreaterThanOrEqual(1, $summary->ready);
        $this->assertGreaterThanOrEqual(1, $summary->notSynced);
    }

    /** An empty scope reports nothing rather than zero progress. */
    public function testAStoreWithNoStoredOrdersIsEmpty(): void
    {
        $this->assertTrue($this->reader->summaryFor(self::STORE)->isEmpty());
    }

    // ------------------------------------------------------------------ the states

    public function testEachStatusIsCountedInItsOwnBucket(): void
    {
        $this->seedOrder(1, 'PENDING');
        $this->seedOrder(2, 'QUEUED');
        $this->seedOrder(3, 'PROCESSING');
        $this->seedOrder(4, 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedOrder(5, 'EMPTY', syncedAt: '2026-10-09 10:00:00');
        $this->seedOrder(6, 'FAILED');

        $summary = $this->reader->summaryFor(self::STORE);

        $this->assertSame(6, $summary->total);
        // SYNCED and EMPTY are both complete: one has lines, the other has none and says so.
        $this->assertSame(2, $summary->ready);
        $this->assertSame(1, $summary->notSynced);
        $this->assertSame(2, $summary->inProgress, 'QUEUED plus a first-time PROCESSING.');
        $this->assertSame(1, $summary->failed);
        $this->assertSame(0, $summary->stale);
    }

    /**
     * The distinction the whole feature turns on, at the aggregate level.
     *
     * `FAILED` over a kept snapshot is comparable and counts as ready — and is also counted as stale,
     * so the bar can say "comparable, but some of it is from an earlier fetch" rather than choosing.
     */
    public function testAFailedOrderWithAKeptSnapshotCountsAsReadyAndStale(): void
    {
        $this->seedOrder(1, 'FAILED', syncedAt: '2026-10-08 09:00:00');
        $this->seedOrder(2, 'FAILED');

        $summary = $this->reader->summaryFor(self::STORE);

        $this->assertSame(2, $summary->total);
        $this->assertSame(1, $summary->ready);
        $this->assertSame(1, $summary->stale);
        $this->assertSame(1, $summary->failed, 'Only the one with nothing stored is a failure.');
        $this->assertTrue($summary->needsAttention());
    }

    public function testAProcessingOrderWithAKeptSnapshotCountsAsReadyAndStale(): void
    {
        $this->seedOrder(1, 'PROCESSING', syncedAt: '2026-10-08 09:00:00');

        $summary = $this->reader->summaryFor(self::STORE);

        $this->assertSame(1, $summary->ready);
        $this->assertSame(1, $summary->stale);
        $this->assertSame(0, $summary->inProgress, 'It has usable items, so it is not merely pending.');
    }

    /**
     * An unrecognised status cannot reach the summary — the schema refuses it.
     *
     * Worth pinning rather than assuming: the reader's `default` branch treats an unknown status as
     * "not synchronised", which is defence in depth for a future migration rather than a state the
     * page can be shown today. If the CHECK constraint is ever dropped, this says so.
     */
    public function testTheSchemaRefusesAStatusTheSummaryWouldNotUnderstand(): void
    {
        $this->expectExceptionMessageMatches('/chk_order58_orders_items_status/');

        $this->seedOrder(1, 'SOMETHING_NEW');
    }

    // ------------------------------------------------------------------ derived figures

    /** One outstanding order must not round up to a finished-looking 100%. */
    public function testProgressNeverReadsAsCompleteWhileWorkRemains(): void
    {
        for ($order = 1; $order <= 200; ++$order) {
            $this->seedOrder($order, $order === 200 ? 'PENDING' : 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        }

        $summary = $this->reader->summaryFor(self::STORE);

        $this->assertSame(199, $summary->ready);
        $this->assertSame(1, $summary->outstanding());
        $this->assertSame(99, $summary->readyPercent(), '199 of 200 must not display as 100%.');
    }

    public function testAFullyReadyStoreReadsAsComplete(): void
    {
        $this->seedOrder(1, 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedOrder(2, 'EMPTY', syncedAt: '2026-10-09 10:00:00');

        $summary = $this->reader->summaryFor(self::STORE);

        $this->assertSame(100, $summary->readyPercent());
        $this->assertSame(0, $summary->outstanding());
        $this->assertFalse($summary->needsAttention());
    }

    // ------------------------------------------------------------------ the query budget

    /** One query, whatever the store contains. The listing renders this on every view. */
    public function testTheSummaryIsOneQueryRegardlessOfOrderCount(): void
    {
        for ($order = 1; $order <= 40; ++$order) {
            $this->seedOrder($order, $order % 2 === 0 ? 'SYNCED' : 'PENDING', syncedAt: '2026-10-09 10:00:00');
        }

        $before = $this->statementCount();
        $summary = $this->reader->summaryFor(self::STORE);

        $this->assertSame(40, $summary->total);
        $this->assertSame(1, $this->statementsSince($before));
    }

    /** And the unfiltered read is one query too. */
    public function testTheUnfilteredSummaryIsAlsoOneQuery(): void
    {
        $this->seedOrder(1, 'PENDING');

        $before = $this->statementCount();
        $this->reader->summaryFor(null);

        $this->assertSame(1, $this->statementsSince($before));
    }

    // ------------------------------------------------------------------ harness

    private function statementCount(): int
    {
        $row = $this->connection
            ->createCommand("SHOW SESSION STATUS LIKE 'Questions'")
            ->queryOne();

        return (int) ($row['Value'] ?? 0);
    }

    /** `Questions` counts the closing `SHOW` too; the opening one is already outside the window. */
    private function statementsSince(int $before): int
    {
        return $this->statementCount() - $before - 1;
    }

    private function seedOrder(
        int $sourceOrderId,
        string $itemsStatus,
        ?string $syncedAt = null,
        int $store = self::STORE,
    ): void {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $sourceOrderId,
            'status' => 'completed',
            'payload_json' => '{}',
            'content_hash' => str_repeat('e', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'items_status' => $itemsStatus,
            'items_synced_at' => $syncedAt,
        ])->execute();
    }

    /** Scoped to this suite's own fixture stores, never a blanket delete. */
    private function cleanup(): void
    {
        foreach ([self::STORE, self::OTHER_STORE] as $store) {
            $this->connection->createCommand()
                ->delete('{{%order58_order_items}}', ['store_account_id' => $store])->execute();
            $this->connection->createCommand()
                ->delete('{{%order58_orders}}', ['account_id' => $store])->execute();
        }
    }
}
