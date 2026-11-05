<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Domain\RecentCaseOrderWindow;
use App\Order58\Infrastructure\DbStoreAudioCounts;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_intersect;
use function array_values;
use function gmdate;
use function str_repeat;

/**
 * Which stores the Manage Orders and Practice Orders pickers list: those with a Case Order placed in the
 * last 30 days, by Order58's creation time — never by when the order was synchronised.
 *
 * The cutoff is passed in rather than read from a clock, so each fixture order sits at an exact distance
 * from it, the boundary included.
 */
final class RecentCaseOrderStoresTest extends Unit
{
    private const DAY = 86_400;

    private const TODAY = 987655850;
    private const TEN_DAYS = 987655851;
    private const TWENTY_NINE_DAYS = 987655852;
    private const EXACTLY_THIRTY = 987655853;
    private const THIRTY_ONE_DAYS = 987655854;
    /** An order placed 40 days ago that this application only synchronised a moment ago. */
    private const OLD_BUT_JUST_SYNCED = 987655855;
    private const DEMO_ORDERS_ONLY = 987655856;
    private const NO_ORDERS = 987655857;
    /** Two recent orders, one store: listed once. */
    private const TWO_RECENT_ORDERS = 987655858;

    private const STORES = [
        self::TODAY, self::TEN_DAYS, self::TWENTY_NINE_DAYS, self::EXACTLY_THIRTY, self::THIRTY_ONE_DAYS,
        self::OLD_BUT_JUST_SYNCED, self::DEMO_ORDERS_ONLY, self::NO_ORDERS, self::TWO_RECENT_ORDERS,
    ];

    private ConnectionInterface $connection;
    private int $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();
        $this->now = (new DateTimeImmutable('2026-11-05 13:43:42'))->getTimestamp();

        foreach (self::STORES as $store) {
            $this->seedStore($store);
        }

        $this->seedOrder(self::TODAY, 1, $this->now - 3600);
        $this->seedOrder(self::TEN_DAYS, 1, $this->now - 10 * self::DAY);
        $this->seedOrder(self::TWENTY_NINE_DAYS, 1, $this->now - 29 * self::DAY);
        $this->seedOrder(self::EXACTLY_THIRTY, 1, $this->now - 30 * self::DAY);
        $this->seedOrder(self::THIRTY_ONE_DAYS, 1, $this->now - 31 * self::DAY);
        $this->seedOrder(self::OLD_BUT_JUST_SYNCED, 1, $this->now - 40 * self::DAY);
        $this->seedOrder(self::TWO_RECENT_ORDERS, 1, $this->now - 2 * self::DAY);
        $this->seedOrder(self::TWO_RECENT_ORDERS, 2, $this->now - 5 * self::DAY);
        $this->seedDemoOrder(self::DEMO_ORDERS_ONLY);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testOnlyStoresWithAnOrderPlacedInTheLastThirtyDaysQualify(): void
    {
        $since = RecentCaseOrderWindow::since((new DateTimeImmutable())->setTimestamp($this->now));
        $found = array_values(array_intersect(
            (new DbStoreAudioCounts($this->connection))->storesWithCaseOrdersCreatedSince($since),
            self::STORES,
        ));

        self::assertSame(
            [self::TODAY, self::TEN_DAYS, self::TWENTY_NINE_DAYS, self::EXACTLY_THIRTY, self::TWO_RECENT_ORDERS],
            $found,
        );
    }

    /** The boundary is inclusive: one second later and the order placed exactly 30 days ago is out. */
    public function testTheBoundaryIsInclusive(): void
    {
        $counts = new DbStoreAudioCounts($this->connection);
        $since = $this->now - 30 * self::DAY;

        self::assertContains(self::EXACTLY_THIRTY, $counts->storesWithCaseOrdersCreatedSince($since));
        self::assertNotContains(self::EXACTLY_THIRTY, $counts->storesWithCaseOrdersCreatedSince($since + 1));
    }

    /** The synchronisation time never qualifies a store: only Order58's creation time does. */
    public function testARecentSyncOfAnOldOrderDoesNotQualifyTheStore(): void
    {
        $found = (new DbStoreAudioCounts($this->connection))
            ->storesWithCaseOrdersCreatedSince($this->now - 30 * self::DAY);

        self::assertNotContains(self::OLD_BUT_JUST_SYNCED, $found);
        self::assertNotContains(self::DEMO_ORDERS_ONLY, $found, 'a demo order is not a Case Order');
        self::assertNotContains(self::NO_ORDERS, $found);
        self::assertNotContains(self::THIRTY_ONE_DAYS, $found);
    }

    // ---------------------------------------------------------------- fixtures

    private function seedStore(int $sourceId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => 'Recent Case Order Fixture ' . $sourceId,
            'active' => 1,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** `synced_at` is always now: every fixture order has just been synchronised, whatever its age. */
    private function seedOrder(int $store, int $order, int $createdAt): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => 16900000 + $order,
            'source_created_at' => $createdAt,
            'payload_json' => '{}',
            'content_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedDemoOrder(int $store): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order_testing_demo_orders}}', [
            'public_id' => str_repeat('d', 32),
            'store_source_id' => $store,
            'source_order_id' => 16900001,
            'demo_order_id' => 16900002,
            'source_filename' => '16900001-16900002.json',
            'content_hash' => str_repeat('0', 64),
            'raw_payload' => '{}',
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** Scoped to this test's own stores; never a blanket delete. */
    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%order_testing_demo_orders}}', ['store_source_id' => self::STORES])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => self::STORES])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => self::STORES])->execute();
    }
}
