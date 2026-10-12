<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function bin2hex;
use function gmdate;
use function random_bytes;
use function str_repeat;

/**
 * The item-synchronisation progress bar on the Order58 orders listing — and its absence elsewhere.
 *
 * ## What is really being guarded
 *
 * Two things.
 *
 * **Where it lives.** It belongs above the stored-orders table, where it can count every mirrored
 * order. It used to sit on the Order Testing store page counting only orders with a demo order, which
 * answered a much narrower question under the same words. Both halves are asserted here, because
 * "moved" is only true if it is gone from the old place as well as present in the new one.
 *
 * **What the figure means.** "4 of 294 ready" is only true of a stated set, so the scope is printed
 * beside it. In particular the bar follows the STORE filter and not the date or order-id filters —
 * a bar that narrowed with the date range would read as progress going backwards.
 *
 * **No provider call**: one grouped count over one local table.
 */
final class Order58ItemProgressCest
{
    private const ORDERS_PAGE = '/admin/order58/orders';

    private const ADMIN = '__kf_item_progress_admin__';
    private const PASSWORD = 'ItemProgressPassw0rd!secure';

    private const STORE_ID = 987655990;
    private const OTHER_STORE_ID = 987655991;
    private const STORE_NAME = 'Item Progress Fixture Store';
    private const HOST = 'kf-item-progress.order58.com';

    private ConnectionInterface $connection;

    /**
     * Demo order ids come from a counter, not from chance.
     *
     * `(store, source_order, demo_order)` is unique, so a random id from a small range collides the
     * moment one order is given two demo orders.
     */
    private int $nextDemoOrderId = 1;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->nextDemoOrderId = 1;
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->seedStore();
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ gone from the store page

    /**
     * The relocation, stated as a test.
     *
     * The bar used to live here and counted only orders with a demo order. It is gone, and no empty
     * container, heading or placeholder is left where it was.
     */
    public function theOrderTestingStorePageHasNoProgressBar(WebTester $I): void
    {
        $this->seedOrder(1, 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedDemo(1);

        $this->signIn($I);
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $I->dontSeeElement('.a2t-item-sync');
        $I->dontSeeElement('.o58-item-sync');
        $I->dontSee('Original order items');
        $I->dontSee('ready to compare');
        $I->dontSee('not synced');
        // Deliberately NOT `dontSeeElement('progress')`: this page has its own arriving-recordings
        // bar, which is none of our business and must survive.
        $I->dontSeeElement('.a2t-item-sync__bar');
        $I->dontSeeElement('.o58-item-sync__bar');
    }

    /** And everything that page is actually for still works. */
    public function theOrderTestingStorePageKeepsAllItsOwnFunctionality(WebTester $I): void
    {
        $this->seedOrder(1, 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedDemo(1);

        $this->signIn($I);
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $I->see(self::STORE_NAME);
        $I->see('Sync demo orders');
        $I->see('All stores');
        $I->seeElement('.page-header__actions');
        // The conversations table, the demo links and the audio actions are this page's purpose.
        $I->see("This store's conversions");
    }

    // ------------------------------------------------------------------ present on the orders page

    public function theOrdersPageShowsTheProgressBar(WebTester $I): void
    {
        $this->seedOrder(1, 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedOrder(2, 'PENDING');

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID);

        $I->seeElement('.o58-item-sync');
        $I->seeElement('.o58-item-sync__bar');
        $I->see('Original order items');
        $I->see('1 of 2 ready to compare');
    }

    /** It sits between the two existing cards, not before or after both. */
    public function theBarSitsBetweenTheSyncAndStoredOrdersSections(WebTester $I): void
    {
        $this->seedOrder(1, 'PENDING');

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID);

        $html = $I->grabPageSource();
        $sync = strpos($html, 'Synchronise orders');
        $bar = strpos($html, 'o58-item-sync');
        $stored = strpos($html, 'Stored orders');

        Assert::assertNotFalse($sync);
        Assert::assertNotFalse($bar);
        Assert::assertNotFalse($stored);
        Assert::assertTrue($sync < $bar, 'The bar must come after "Synchronise orders".');
        Assert::assertTrue($bar < $stored, 'The bar must come before "Stored orders".');
    }

    // ------------------------------------------------------------------ the scope it reports

    /** Every stored order counts now, not only the ones with a demo order. */
    public function everyStoredOrderIsCountedNotJustTestedOnes(WebTester $I): void
    {
        $this->seedOrder(1, 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedDemo(1);
        $this->seedOrder(2, 'PENDING');
        $this->seedOrder(3, 'PENDING');

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID);

        $I->see('1 of 3 ready to compare');
        $I->see('stored orders of the selected store');
    }

    /**
     * The date filter narrows the TABLE, never the bar.
     *
     * A progress figure that shrank when somebody filtered by date would read as work being undone.
     */
    public function theDateFilterDoesNotNarrowTheProgressFigure(WebTester $I): void
    {
        $this->seedOrder(1, 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedOrder(2, 'PENDING');

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID
            . '&list_from=2020-01-01&list_to=2020-01-02');

        $I->see('1 of 2 ready to compare');
        $I->see('Date and order filters below do not change this count');
    }

    /** Unfiltered, it speaks for every store and says so. */
    public function withoutAStoreFilterTheScopeSaysEveryStore(WebTester $I): void
    {
        $this->seedOrder(1, 'PENDING');

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE);

        $I->seeElement('.o58-item-sync');
        $I->see('stored orders, every store');
    }

    public function eachOutstandingStateGetsItsOwnBadge(WebTester $I): void
    {
        $this->seedOrder(1, 'PENDING');
        $this->seedOrder(2, 'QUEUED');
        $this->seedOrder(3, 'FAILED');
        $this->seedOrder(4, 'SYNCED', syncedAt: '2026-10-09 10:00:00');

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID);

        $I->see('1 not synced', '.o58-item-sync');
        $I->see('1 in progress', '.o58-item-sync');
        $I->see('1 failed', '.o58-item-sync');
        $I->see('1 of 4 ready to compare');
    }

    /** A kept snapshot is comparable AND flagged — the distinction the feature turns on. */
    public function aKeptSnapshotIsCountedAsReadyAndFlaggedAsStale(WebTester $I): void
    {
        $this->seedOrder(1, 'FAILED', syncedAt: '2026-10-08 09:00:00');

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID);

        $I->see('1 of 1 ready to compare');
        $I->see('1 from an earlier fetch', '.o58-item-sync');
        $I->dontSee('1 failed', '.o58-item-sync');
    }

    public function aFullyReadyStoreSaysSo(WebTester $I): void
    {
        $this->seedOrder(1, 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedOrder(2, 'EMPTY', syncedAt: '2026-10-09 10:00:00');

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID);

        $I->see('Every order in this view has its items');
        $I->see('2 of 2 ready to compare');
    }

    /** The bar is a real <progress>, so the browser announces it without any script. */
    public function theBarCarriesItsRealValueAndMaximum(WebTester $I): void
    {
        $this->seedOrder(1, 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedOrder(2, 'PENDING');
        $this->seedOrder(3, 'PENDING');

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID);

        $I->seeElement('progress.o58-item-sync__bar[value="1"][max="3"]');
    }

    // ------------------------------------------------------------------ the page still works

    /** The new card must not have displaced what this page is for. */
    public function theOrdersPageKeepsItsFiltersActionsAndTable(WebTester $I): void
    {
        $this->seedOrder(1, 'SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedOrder(2, 'PENDING');

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID);

        $I->see('Synchronise orders');
        $I->see('Stored orders');
        $I->see('Order Items', 'th');
        $I->seeElement('.o58-items-form');
        // The listing's own filters still render and still carry the store.
        $I->seeElement('form[method="get"]');
        $I->see('orders stored.');
    }

    /** Pagination and the store filter are untouched by the new card. */
    public function theStoreFilterAndPaginationStillWork(WebTester $I): void
    {
        for ($order = 1; $order <= 3; ++$order) {
            $this->seedOrder($order, 'PENDING');
        }
        $this->seedOrder(1, 'PENDING', store: self::OTHER_STORE_ID);

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID);

        // Three of this store's orders counted, and the fourth store's order excluded.
        $I->see('0 of 3 ready to compare');
        $I->dontSee('of 4 ready to compare');
    }

    public function aGuestSeesNoProgressAtAll(WebTester $I): void
    {
        $this->seedOrder(1, 'SYNCED', syncedAt: '2026-10-09 10:00:00');

        $I->amOnPage(self::ORDERS_PAGE);

        $I->seeCurrentUrlEquals('/login');
        $I->dontSeeElement('.o58-item-sync');
    }

    // ------------------------------------------------------------------ harness

    private function url(): string
    {
        return '/order-testing/store/' . self::STORE_ID;
    }

    private function signIn(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
    }

    private function seedStore(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => self::STORE_ID,
            'name' => self::STORE_NAME,
            'active' => 1,
            'host' => self::HOST,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        // The store page resolves a store through `knowledge_bases`, not the mirror.
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => self::STORE_NAME,
            'slug' => 'kf-item-progress-' . self::STORE_ID,
            'source_system' => 'order58',
            'source_store_id' => self::STORE_ID,
            'source_name' => self::STORE_NAME,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedOrder(
        int $sourceOrderId,
        string $itemsStatus,
        ?string $syncedAt = null,
        int $store = self::STORE_ID,
    ): void {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $sourceOrderId,
            'status' => 'completed',
            'payload_json' => '{}',
            'content_hash' => str_repeat('a', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'items_status' => $itemsStatus,
            'items_synced_at' => $syncedAt,
        ])->execute();
    }

    private function seedDemo(int $sourceOrderId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order_testing_demo_orders}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => self::STORE_ID,
            'source_order_id' => $sourceOrderId,
            'demo_order_id' => 800000 + $this->nextDemoOrderId++,
            'status' => 'completed',
            'total_amount' => '1.00000',
            'source_filename' => $sourceOrderId . '-demo.json',
            'content_hash' => str_repeat('b', 64),
            'raw_payload' => '{}',
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** Scoped to this suite's own fixtures, never a blanket delete. */
    private function cleanup(): void
    {
        $adminIds = (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::ADMIN])->column();

        $stores = [self::STORE_ID, self::OTHER_STORE_ID];

        $this->connection->createCommand()
            ->delete('{{%order_testing_demo_orders}}', ['store_source_id' => $stores])->execute();
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
}
