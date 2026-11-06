<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Infrastructure\DbStoreAudioCounts;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_intersect;
use function array_values;
use function gmdate;
use function str_repeat;
use function time;

/**
 * Which stores the "Case Orders" data source lists on the Manage Orders picker: those with at least one
 * Case Order, however long ago it was placed. There is no date window (the 30-day one was removed on
 * 2026-11-06).
 *
 * A store with no Case Order — none at all, or only demo orders — is not on this list, exactly as before.
 * ("All stores" lists it; that chip applies no store list at all.)
 */
final class CaseOrderStoresOfAnyAgeTest extends Unit
{
    private const DAY = 86_400;

    private const TODAY = 987655850;
    private const FIFTEEN_DAYS = 987655851;
    private const EXACTLY_THIRTY = 987655852;
    private const THIRTY_ONE_DAYS = 987655853;
    private const FORTY_FIVE_DAYS = 987655854;
    private const SIX_MONTHS = 987655855;
    private const TWO_YEARS = 987655856;
    private const DEMO_ORDERS_ONLY = 987655857;
    private const NO_ORDERS = 987655858;
    /** Two orders, one store: listed once. */
    private const TWO_ORDERS = 987655859;

    private const STORES = [
        self::TODAY, self::FIFTEEN_DAYS, self::EXACTLY_THIRTY, self::THIRTY_ONE_DAYS, self::FORTY_FIVE_DAYS,
        self::SIX_MONTHS, self::TWO_YEARS, self::DEMO_ORDERS_ONLY, self::NO_ORDERS, self::TWO_ORDERS,
    ];

    private ConnectionInterface $connection;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();
        $now = time();

        foreach (self::STORES as $store) {
            $this->seedStore($store);
        }

        $this->seedOrder(self::TODAY, 1, $now - 3600);
        $this->seedOrder(self::FIFTEEN_DAYS, 1, $now - 15 * self::DAY);
        $this->seedOrder(self::EXACTLY_THIRTY, 1, $now - 30 * self::DAY);
        $this->seedOrder(self::THIRTY_ONE_DAYS, 1, $now - 31 * self::DAY);
        $this->seedOrder(self::FORTY_FIVE_DAYS, 1, $now - 45 * self::DAY);
        $this->seedOrder(self::SIX_MONTHS, 1, (new DateTimeImmutable('-6 months'))->getTimestamp());
        $this->seedOrder(self::TWO_YEARS, 1, (new DateTimeImmutable('-2 years'))->getTimestamp());
        $this->seedOrder(self::TWO_ORDERS, 1, $now - 2 * self::DAY);
        $this->seedOrder(self::TWO_ORDERS, 2, $now - 400 * self::DAY);
        $this->seedDemoOrder(self::DEMO_ORDERS_ONLY);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    /** Today, 15 days, 30, 31, 45 days, six months, two years: every one qualifies. */
    public function testAStoreQualifiesWhateverTheAgeOfItsOrders(): void
    {
        self::assertSame(
            [
                self::TODAY, self::FIFTEEN_DAYS, self::EXACTLY_THIRTY, self::THIRTY_ONE_DAYS,
                self::FORTY_FIVE_DAYS, self::SIX_MONTHS, self::TWO_YEARS, self::TWO_ORDERS,
            ],
            $this->qualifying(),
        );
    }

    /** No Case Order, or only demo orders: not on the Case Orders list, as before the window. */
    public function testAStoreWithoutACaseOrderStillDoesNotQualify(): void
    {
        $found = $this->qualifying();

        self::assertNotContains(self::NO_ORDERS, $found);
        self::assertNotContains(self::DEMO_ORDERS_ONLY, $found, 'a demo order is not a Case Order');
    }

    /** @return list<int> the fixture stores on the Case Orders list, in id order */
    private function qualifying(): array
    {
        return array_values(array_intersect(
            (new DbStoreAudioCounts($this->connection))->storesWithCaseOrders(),
            self::STORES,
        ));
    }

    // ---------------------------------------------------------------- fixtures

    private function seedStore(int $sourceId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => 'Case Order Age Fixture ' . $sourceId,
            'active' => 1,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

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
