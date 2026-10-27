<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Environment;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function bin2hex;
use function gmdate;
use function hash;
use function json_encode;
use function random_bytes;
use function str_repeat;

/**
 * The Original Order Items column, over real HTTP.
 *
 * **No test here reaches any API**, and since Stage 7a there is nothing it could reach: the per-order
 * items endpoint, its two manual buttons and its POST route were removed, because the Case Order
 * response carries each order's lines inside the order.
 *
 * ## What is really being guarded
 *
 * That the column distinguishes its six states. `No items` and `Failed` must never render the same
 * way: one means the order has no lines, the other means we do not know — and that distinction is
 * what the comparison reads to decide whether it has anything to compare against. It outlived the
 * endpoint that used to set those states, and now describes what the Case Order import sets.
 *
 * The authorization cases that used to live here went with the endpoint: a POST route that does not
 * exist cannot be asked for another store's order.
 */
final class Order58OrderItemsCest
{
    private const ADMIN = '__kf_o58_items_admin__';
    private const PASSWORD = 'Order58ItemsPassw0rd!secure';

    private const STORE_ID = 987655940;
    private const OTHER_STORE_ID = 987655941;
    private const STORE_NAME = 'Order58 Items Fixture Store';
    private const HOST = 'kf-items-fixture.order58.com';

    private const ORDER = 16800401;
    private const OTHER_STORE_ORDER = 16800499;
    private const DEMO_ORDER = 16630821;
    private const PHONE = '19178532637';

    /** 2026-10-05 12:00:00 UTC — inside the date range the filter tests use. */
    private const CREATED_AT = 1791201600;

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->seedStore(self::STORE_ID, self::STORE_NAME);
        $this->seedStore(self::OTHER_STORE_ID, self::STORE_NAME . ' (other)');
        $this->seedOrder(self::STORE_ID, self::ORDER);
        $this->seedOrder(self::OTHER_STORE_ID, self::OTHER_STORE_ORDER);
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ the column

    public function theOrdersPageHasAnOrderItemsColumn(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/admin/order58/orders?account_id=' . self::STORE_ID);

        $I->see('Order Items', 'th');
    }

    /** A historical order has never been considered, and must not claim to have no items. */
    public function anOrderThatWasNeverSyncedSaysSo(WebTester $I): void
    {
        $this->setItemState(self::ORDER, 'PENDING');

        $this->signIn($I);
        $I->amOnPage('/admin/order58/orders?account_id=' . self::STORE_ID);

        $I->see('Not synced', '.o58-items-cell');
    }

    public function aSyncedOrderShowsItsItemCount(WebTester $I): void
    {
        $this->setItemState(self::ORDER, 'SYNCED', count: 4);

        $this->signIn($I);
        $I->amOnPage('/admin/order58/orders?account_id=' . self::STORE_ID);

        $I->see('Synced', '.o58-items-cell');
        $I->see('4 items', '.o58-items-cell');
    }

    public function oneItemIsCountedInTheSingular(WebTester $I): void
    {
        $this->setItemState(self::ORDER, 'SYNCED', count: 1);

        $this->signIn($I);
        $I->amOnPage('/admin/order58/orders?account_id=' . self::STORE_ID);

        // Scoped to the count span, not the whole cell. The cell also contains the button labelled
        // "Sync Order Items", and Codeception matches case-insensitively — so "1 item" immediately
        // followed by "Sync…" does contain "1 items", and asserting on the cell would always fail.
        $I->see('1 item', '.o58-items-cell .util-muted');
        $I->dontSee('1 items', '.o58-items-cell .util-muted');
    }

    /**
     * The distinction the whole design rests on.
     *
     * `EMPTY` means the provider answered and this order has no lines — comparable. `FAILED` means we
     * do not know. If these rendered alike, a trainee's correct empty order would be indistinguishable
     * from an outage.
     */
    public function aVerifiedEmptyOrderIsNotShownAsAFailure(WebTester $I): void
    {
        $this->setItemState(self::ORDER, 'EMPTY');

        $this->signIn($I);
        $I->amOnPage('/admin/order58/orders?account_id=' . self::STORE_ID);

        $I->see('No items', '.o58-items-cell');
        $I->dontSee('Failed', '.o58-items-cell');
    }

    public function aFailedOrderIsNotShownAsEmpty(WebTester $I): void
    {
        $this->setItemState(self::ORDER, 'FAILED');

        $this->signIn($I);
        $I->amOnPage('/admin/order58/orders?account_id=' . self::STORE_ID);

        $I->see('Failed', '.o58-items-cell');
        $I->dontSee('No items', '.o58-items-cell');
    }

    /**
     * A failure over a kept snapshot is a freshness warning, not an absence of data.
     *
     * The listing has to make the same distinction the comparison page does, or an operator scanning
     * for work to do would re-sync orders that already have usable items.
     */
    public function aFailedOrderWithAKeptSnapshotReadsAsStaleNotFailed(WebTester $I): void
    {
        $this->setItemState(self::ORDER, 'FAILED', count: 3, syncedAt: '2026-10-08 09:00:00');

        $this->signIn($I);
        $I->amOnPage('/admin/order58/orders?account_id=' . self::STORE_ID);

        $I->see('Stale', '.o58-items-cell');
        $I->see('3 items from 2026-10-08', '.o58-items-cell');
        $I->dontSee('Failed', '.o58-items-cell');
    }

    /** And with nothing ever stored it is a plain failure, with no date to offer. */
    public function aFailedOrderWithNoSnapshotReadsAsFailed(WebTester $I): void
    {
        $this->setItemState(self::ORDER, 'FAILED');

        $this->signIn($I);
        $I->amOnPage('/admin/order58/orders?account_id=' . self::STORE_ID);

        $I->see('Failed', '.o58-items-cell');
        $I->dontSee('Stale', '.o58-items-cell');
        $I->dontSee('from 20', '.o58-items-cell');
    }

    /** The exact timestamp stays available rather than being rounded away to a date. */
    public function theLastSuccessfulSyncTimeIsAvailableInFull(WebTester $I): void
    {
        $this->setItemState(self::ORDER, 'SYNCED', count: 2, syncedAt: '2026-10-08 09:00:00');

        $this->signIn($I);
        $I->amOnPage('/admin/order58/orders?account_id=' . self::STORE_ID);

        $I->seeElement('.o58-items-cell [title="Last successful sync: 2026-10-08 09:00:00 UTC"]');
    }

    /** A refresh in flight over a snapshot says both things at once. */
    public function aRefreshingOrderStillNamesTheItemsItHas(WebTester $I): void
    {
        $this->setItemState(self::ORDER, 'PROCESSING', count: 4, syncedAt: '2026-10-08 09:00:00');

        $this->signIn($I);
        $I->amOnPage('/admin/order58/orders?account_id=' . self::STORE_ID);

        $I->see('Syncing', '.o58-items-cell');
        $I->see('4 items from 2026-10-08', '.o58-items-cell');
    }

    public function anOrderInFlightSaysItIsSyncing(WebTester $I): void
    {
        $this->setItemState(self::ORDER, 'PROCESSING');

        $this->signIn($I);
        $I->amOnPage('/admin/order58/orders?account_id=' . self::STORE_ID);

        $I->see('Syncing', '.o58-items-cell');
    }

    // ------------------------------------------------------------------ harness

    /**
     * These two press the sync button expecting the kill switch to refuse it.
     *
     * With `ORDER58_ITEM_SYNC_ENABLED=true` the same click would reach the real provider — an
     * unapproved live request, made from a test run, for a fixture account that does not exist at
     * Order58. So the suite steps aside rather than firing it.
     *
     * Skipping is the right answer rather than forcing the flag off: the flag is an operator's
     * deliberate local choice, and a test suite that quietly rewrote `.env` would be worse than one
     * that declines to run two tests.
     */
    private function skipWhileTheFeatureIsOn(WebTester $I): void
    {
        if (Environment::bool('ORDER58_ITEM_SYNC_ENABLED')) {
            Assert::markTestSkipped(
                'ORDER58_ITEM_SYNC_ENABLED is true, so pressing the button would call the live Order58 '
                . 'Item API. This test describes the switched-off behaviour.',
            );
        }
    }


    private function comparisonUrl(): string
    {
        return '/order-testing/store/' . self::STORE_ID . '/order/' . self::ORDER
            . '/demo/' . self::DEMO_ORDER;
    }

    private function signIn(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
    }

    private function itemStatus(int $sourceOrderId, int $store = self::STORE_ID): string
    {
        return (string) $this->column($sourceOrderId, 'items_status', $store);
    }

    private function column(int $sourceOrderId, string $column, int $store = self::STORE_ID): mixed
    {
        /** @var mixed $value */
        $value = (new Query($this->connection))
            ->select($column)
            ->from('{{%order58_orders}}')
            ->where(['account_id' => $store, 'source_order_id' => $sourceOrderId])
            ->scalar();

        return $value;
    }

    private function setItemState(
        int $sourceOrderId,
        string $status,
        int $count = 0,
        ?string $syncedAt = null,
        int $store = self::STORE_ID,
    ): void {
        $this->connection->createCommand()->update(
            '{{%order58_orders}}',
            ['items_status' => $status, 'items_count' => $count, 'items_synced_at' => $syncedAt],
            ['account_id' => $store, 'source_order_id' => $sourceOrderId],
        )->execute();
    }

    private function seedStore(int $sourceId, string $name): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => $name,
            'active' => 1,
            'host' => self::HOST,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        // The Order Testing store page resolves a store through `knowledge_bases`, not the mirror.
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => $name,
            'slug' => 'kf-o58-items-' . $sourceId,
            'source_system' => 'order58',
            'source_store_id' => $sourceId,
            'source_name' => $name,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedOrder(int $storeId, int $orderId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $payload = (string) json_encode($this->orderDocument($orderId));

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $storeId,
            'source_order_id' => $orderId,
            'reservation_phone' => self::PHONE,
            'type' => 'delivery',
            'payment_method' => 'cash',
            'status' => 'completed',
            'total_amount' => '4.28000',
            'payload_json' => $payload,
            'content_hash' => hash('sha256', $payload),
            'synced_at' => $now,
            // The listing filters and sorts on `source_created_at`, so an order without one is
            // invisible to every date range. Fixed inside the range the filter tests ask for.
            'source_created_at' => self::CREATED_AT,
            'created_at' => $now,
            'updated_at' => $now,
            'items_status' => 'PENDING',
        ])->execute();
    }

    private function seedDemoOrder(int $demoOrderId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $payload = (string) json_encode($this->orderDocument($demoOrderId));

        $this->connection->createCommand()->insert('{{%order_testing_demo_orders}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => self::STORE_ID,
            'source_order_id' => self::ORDER,
            'demo_order_id' => $demoOrderId,
            'customer_phone' => self::PHONE,
            'customer_first_name' => 'testa',
            'order_type' => 'delivery',
            'payment_method' => 'cash',
            'status' => 'completed',
            'total_amount' => '4.28000',
            'address' => '162 N Main St',
            'city' => 'Freeport',
            'source_filename' => self::ORDER . '-' . $demoOrderId . '.json',
            'content_hash' => hash('sha256', $payload),
            'raw_payload' => $payload,
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** @return array<string, mixed> */
    private function orderDocument(int $id): array
    {
        return [
            'id' => (string) $id,
            'status' => 'completed',
            'type' => 'delivery',
            'total_amount' => '4.28000',
            'payment_method' => 'cash',
            'created_at' => (string) 1790914956,
            'data' => (string) json_encode(['reservation' => ['phone' => self::PHONE]]),
            'items' => [[
                'name' => 'Wonton Soup', 'quantity' => 2, 'price' => '2.00000', 'total' => '4.00000',
            ]],
        ];
    }

    /** Scoped to this suite's own fixtures, never a blanket delete. */
    private function cleanup(): void
    {
        $stores = [self::STORE_ID, self::OTHER_STORE_ID];
        $adminIds = (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::ADMIN])->column();

        $this->connection->createCommand()
            ->delete('{{%order_testing_demo_orders}}', ['store_source_id' => $stores])->execute();
        $this->connection->createCommand()
            ->delete('{{%order_testing_attempts}}', ['store_source_id' => $stores])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_order_items}}', ['store_account_id' => $stores])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => $stores])->execute();

        if ($adminIds !== []) {
            $this->connection->createCommand()
                ->delete('{{%admin_users}}', ['id' => $adminIds])->execute();
        }

        $this->connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['source_store_id' => $stores])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => $stores])->execute();
    }

    /**
     * The removed controls stay removed, and the route stays unmounted.
     *
     * Worth a test rather than a note: the status column still says "Not synced", which is exactly the
     * state a button would seem to fix. Reintroducing one would post to a route that no longer exists
     * — a dead control on a management page, failing only when somebody pressed it.
     */
    public function theRemovedItemSyncControlsAreGone(WebTester $I): void
    {
        $this->signIn($I);

        // `_before` already seeded this store's order at PENDING, which is the state a button would
        // appear to fix.
        $I->amOnPage('/admin/order58/orders');

        // The column and its status survive; the control does not.
        $I->see('Order items');
        $I->dontSee('Sync Order Items');
        $I->dontSeeElement('form.o58-items-form');

        /*
         * And posting to the old endpoint is refused rather than acted on.
         *
         * The exact code is deliberately not pinned. CSRF is checked before routing, so a POST with
         * no token is refused as 422 whether or not the route exists; without routing in the picture
         * it would be 404. Either is a refusal, and what this asserts is that nothing succeeds — a
         * 2xx or the 303 the old action redirected with would mean the endpoint came back.
         */
        $I->sendAjaxPostRequest('/admin/order58/order-items/sync', ['account_id' => self::STORE_ID]);
        $I->seeResponseCodeIsClientError();
    }
}
