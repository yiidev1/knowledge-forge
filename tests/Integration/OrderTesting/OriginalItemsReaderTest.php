<?php

declare(strict_types=1);

namespace App\Tests\Integration\OrderTesting;

use App\OrderTesting\Domain\Comparison\ItemAvailability;
use App\OrderTesting\Infrastructure\DbOriginalItemsReader;
use App\Shared\Infrastructure\Db\DatabaseTarget;
use App\Shared\Order58\Items\ItemCanonicalizer;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function gmdate;
use function json_encode;
use function str_repeat;

/**
 * Reading the original order's items out of real MySQL. **No provider call, ever.**
 *
 * ## What is really being guarded
 *
 * Two things the unit tests cannot see.
 *
 * First, that the availability rule is applied to what the *database* actually stores — `items_status`
 * as a string and `items_synced_at` as a nullable datetime — rather than to an enum a test constructed.
 * The whole defect fixed in this phase came from mis-reading exactly these two columns.
 *
 * Second, that the read is bounded. The comparison page runs it on every view, so an N+1 here would be
 * one query per line of every order a reviewer opens.
 */
final class OriginalItemsReaderTest extends Unit
{
    private const STORE = 987655960;
    private const OTHER_STORE = 987655961;
    private const ORDER = 16800401;

    private ConnectionInterface $connection;
    private DbOriginalItemsReader $reader;
    private bool $ready = false;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        DatabaseTarget::resolve($this->connection)->assertLocalForSchemaChange();

        $exists = (int) $this->connection->createCommand(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order58_order_items'",
        )->queryScalar();

        if ($exists === 0) {
            $this->markTestSkipped('order58_order_items does not exist — the migration has not been applied.');
        }

        $this->ready = true;
        $this->reader = new DbOriginalItemsReader($this->connection);
        $this->cleanup();
    }

    protected function _after(): void
    {
        if ($this->ready) {
            $this->cleanup();
        }
    }

    // ------------------------------------------------------------------ availability from real columns

    /** An order nobody has ever asked about. The common case until the feature is switched on. */
    public function testAPendingOrderIsNotComparable(): void
    {
        $this->seedOrder('PENDING');

        $items = $this->reader->read(self::STORE, self::ORDER);

        $this->assertSame(ItemAvailability::NeverRequested, $items->availability);
        $this->assertFalse($items->isComparable());
        $this->assertSame([], $items->items);
    }

    public function testAQueuedOrderIsNotComparable(): void
    {
        $this->seedOrder('QUEUED');

        $this->assertFalse($this->reader->read(self::STORE, self::ORDER)->isComparable());
    }

    public function testASyncedOrderReturnsItsLinesInProviderOrder(): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(2, 'Wonton Soup', position: 1);
        $this->seedItem(1, '[F7]Fried Chicken Wings (4)', position: 0);

        $items = $this->reader->read(self::STORE, self::ORDER);

        $this->assertSame(ItemAvailability::Synced, $items->availability);
        $this->assertCount(2, $items->items);
        // `position`, not insertion order and not the item id.
        $this->assertSame('[F7]Fried Chicken Wings (4)', $items->items[0]->name);
        $this->assertSame('Wonton Soup', $items->items[1]->name);
        $this->assertSame('2026-10-09 10:00:00', $items->syncedAt);
    }

    /** The provider stated the order has no lines. Comparable, and emphatically not a failure. */
    public function testAnEmptyOrderIsComparableWithNoLines(): void
    {
        $this->seedOrder('EMPTY', syncedAt: '2026-10-09 10:00:00');

        $items = $this->reader->read(self::STORE, self::ORDER);

        $this->assertSame(ItemAvailability::AuthoritativeEmpty, $items->availability);
        $this->assertTrue($items->isComparable());
        $this->assertSame([], $items->items);
    }

    /** The distinction that cannot be made without reading `items_synced_at`. */
    public function testAFailedOrderWithNoSnapshotIsNotComparable(): void
    {
        $this->seedOrder('FAILED');
        $this->seedItem(1, 'Wonton Soup');

        $items = $this->reader->read(self::STORE, self::ORDER);

        $this->assertSame(ItemAvailability::Unavailable, $items->availability);
        $this->assertFalse($items->isComparable());
        // Withheld on purpose: rows with no successful sync behind them are not a snapshot.
        $this->assertSame([], $items->items);
    }

    public function testAFailedOrderWithAKeptSnapshotIsComparedAndStale(): void
    {
        $this->seedOrder('FAILED', syncedAt: '2026-10-08 09:00:00');
        $this->seedItem(1, 'Wonton Soup');

        $items = $this->reader->read(self::STORE, self::ORDER);

        $this->assertSame(ItemAvailability::StaleAfterFailure, $items->availability);
        $this->assertTrue($items->isComparable());
        $this->assertTrue($items->availability->isStale());
        $this->assertCount(1, $items->items);
        $this->assertSame('2026-10-08 09:00:00', $items->syncedAt);
    }

    public function testAProcessingOrderWithAKeptSnapshotStillOffersIt(): void
    {
        $this->seedOrder('PROCESSING', syncedAt: '2026-10-08 09:00:00');
        $this->seedItem(1, 'Wonton Soup');

        $items = $this->reader->read(self::STORE, self::ORDER);

        $this->assertSame(ItemAvailability::StaleWhileFetching, $items->availability);
        $this->assertCount(1, $items->items, 'A refresh in flight must not hide the previous answer.');
    }

    public function testAProcessingOrderWithNoSnapshotIsNotComparable(): void
    {
        $this->seedOrder('PROCESSING');

        $this->assertSame(ItemAvailability::Fetching, $this->reader->read(self::STORE, self::ORDER)->availability);
    }

    /** An order the mirror does not hold at all. */
    public function testAnUnmirroredOrderIsNeverRequested(): void
    {
        $this->assertSame(
            ItemAvailability::NeverRequested,
            $this->reader->read(self::STORE, 999999999)->availability,
        );
    }

    // ------------------------------------------------------------------ scoping and shape

    /** One store's lines can never render on another store's page. */
    public function testTheReadIsScopedToTheStore(): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, 'Wonton Soup');
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00', store: self::OTHER_STORE);
        $this->seedItem(1, 'Someone Elses Dish', store: self::OTHER_STORE);

        $items = $this->reader->read(self::STORE, self::ORDER);

        $this->assertCount(1, $items->items);
        $this->assertSame('Wonton Soup', $items->items[0]->name);
    }

    /** The canonical form survives storage, so the page gets modifiers and the un-prefixed name. */
    public function testTheStoredCanonicalFormIsReadBackIntact(): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, '[F7]Fried Chicken Wings (4)', additionals: [
            ['additional_name' => 'Sauce', 'option_name' => 'Extra Hot', 'qty' => '2', 'price' => '0.50000'],
        ]);

        $item = $this->reader->read(self::STORE, self::ORDER)->items[0];

        $this->assertSame('[F7]Fried Chicken Wings (4)', $item->name, 'The prefix must survive.');
        $this->assertSame('Fried Chicken Wings (4)', $item->productName);
        $this->assertSame('F7', $item->sn);
        $this->assertSame('11.35000', $item->unitPrice);
        $this->assertSame(['Sauce: Extra Hot x2'], $item->options);
        $this->assertNotSame('', (string) $item->modifierSignature);
    }

    /** A row whose canonical form will not decode is skipped, not fatal. */
    public function testAnUndecodableLineIsSkippedRatherThanFailingThePage(): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, 'Wonton Soup', position: 0);
        $this->seedRawItem(2, 'Broken', 'not json at all', position: 1);

        $items = $this->reader->read(self::STORE, self::ORDER);

        $this->assertCount(1, $items->items);
        $this->assertSame('Wonton Soup', $items->items[0]->name);
    }

    // ------------------------------------------------------------------ the query budget

    /**
     * Two queries for a comparable order, whatever it contains, and one when it is not comparable.
     *
     * The page runs this on every view. An N+1 here would be one query per line of every order a
     * reviewer opens, which is exactly the kind of regression that only shows up on a real order.
     */
    public function testTheReadIsBoundedToTwoQueriesRegardlessOfItemCount(): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');

        for ($i = 1; $i <= 25; ++$i) {
            $this->seedItem($i, 'Dish ' . $i, position: $i);
        }

        $before = $this->statementCount();
        $items = $this->reader->read(self::STORE, self::ORDER);
        $used = $this->statementsSince($before);

        $this->assertCount(25, $items->items);
        $this->assertSame(2, $used, 'One row for the sync state, one bounded read for the lines.');
    }

    /** And nothing is read at all when there is nothing the page may compare against. */
    public function testANotComparableOrderCostsASingleQuery(): void
    {
        $this->seedOrder('PENDING');
        $this->seedItem(1, 'Wonton Soup');

        $before = $this->statementCount();
        $this->reader->read(self::STORE, self::ORDER);

        $this->assertSame(1, $this->statementsSince($before), 'No item query when there is nothing to compare.');
    }

    // ------------------------------------------------------------------ harness

    /** Statements executed by this session, from the server's own counter. */
    private function statementCount(): int
    {
        $row = $this->connection
            ->createCommand("SHOW SESSION STATUS LIKE 'Questions'")
            ->queryOne();

        return (int) ($row['Value'] ?? 0);
    }

    /**
     * Statements the code under test issued, with the measurement's own cost removed.
     *
     * `Questions` counts the `SHOW` that reads it. The opening reading is taken before the window and
     * so is already in `$before`; the closing one lands inside it and is subtracted here. Getting this
     * wrong makes the budget look one query worse than it is, which is how a correct bound gets
     * "fixed" by raising the expected number.
     */
    private function statementsSince(int $before): int
    {
        return $this->statementCount() - $before - 1;
    }

    private function seedOrder(string $itemsStatus, ?string $syncedAt = null, int $store = self::STORE): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => self::ORDER,
            'status' => 'completed',
            'payload_json' => '{}',
            'content_hash' => str_repeat('c', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'items_status' => $itemsStatus,
            'items_synced_at' => $syncedAt,
        ])->execute();
    }

    /** @param list<array<string, mixed>> $additionals */
    private function seedItem(
        int $sourceItemId,
        string $name,
        int $position = 0,
        array $additionals = [],
        int $store = self::STORE,
    ): void {
        $canonical = (new ItemCanonicalizer())->canonicalize([
            'id' => $sourceItemId,
            'qty' => '1',
            'name' => $name,
            'price' => '11.35000',
            'deleted' => '0',
            'product_id' => 500000 + $sourceItemId,
            'data' => [
                'product' => ['id' => 500000 + $sourceItemId, 'sn' => 'F7', 'name' => 'Fried Chicken Wings (4)'],
                'additionals' => $additionals === [] ? null : $additionals,
                'combinations' => null,
            ],
        ]);

        $this->seedRawItem(
            $sourceItemId,
            $name,
            (string) json_encode($canonical->toArray()),
            $position,
            $store,
        );
    }

    private function seedRawItem(
        int $sourceItemId,
        string $name,
        string $comparisonJson,
        int $position = 0,
        int $store = self::STORE,
    ): void {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_order_items}}', [
            'store_account_id' => $store,
            'source_order_id' => self::ORDER,
            'source_item_id' => $sourceItemId,
            'name' => $name,
            'qty' => '1',
            'price' => '11.35000',
            'position' => $position,
            'comparison_json' => $comparisonJson,
            'content_hash' => str_repeat('d', 64),
            'created_at' => $now,
            'updated_at' => $now,
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
