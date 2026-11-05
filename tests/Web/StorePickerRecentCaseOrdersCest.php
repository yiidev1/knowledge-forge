<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function gmdate;
use function str_repeat;
use function time;
use function urlencode;

/**
 * Manage Orders and Practice Orders list only stores with a Case Order placed in the last 30 days.
 *
 * Two fixture stores that differ in one thing: one has an order placed five days ago, the other only an
 * order placed 45 days ago — synchronised just now, so the test also proves the sync time does not count.
 * The search narrows each page to these two stores, so the real mirror's stores cannot affect the result.
 * The Audio-to-Text picker, which shares the code, still lists both.
 */
final class StorePickerRecentCaseOrdersCest
{
    private const USERNAME = '__kf_recent_window_admin__';
    private const PASSWORD = 'RecentWindowPassw0rd!secure';
    private const FRESH = 987655965;
    private const STALE = 987655966;
    private const FRESH_NAME = 'ZZ Recent Window Fresh';
    private const STALE_NAME = 'ZZ Recent Window Stale';
    private const SEARCH = 'ZZ Recent Window';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->seedStore(self::FRESH, self::FRESH_NAME);
        $this->seedStore(self::STALE, self::STALE_NAME);
        $this->seedOrder(self::FRESH, 16900101, time() - 5 * 86_400);
        $this->seedOrder(self::STALE, 16900102, time() - 45 * 86_400);

        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function manageOrdersListsOnlyTheStoreWithARecentCaseOrder(WebTester $I): void
    {
        $I->amOnPage('/order-testing?q=' . urlencode(self::SEARCH));
        $I->seeResponseCodeIs(200);

        $I->see(self::FRESH_NAME, '.store-grid');
        $I->dontSee(self::STALE_NAME);
        $I->see('Case Order placed in the last 30', '[data-recent-case-order-note]');
    }

    /** "All stores" widens the data source, never the window. */
    public function theWindowStillAppliesUnderEveryDataSource(WebTester $I): void
    {
        $I->amOnPage('/order-testing?audio=all&q=' . urlencode(self::SEARCH));
        $I->see(self::FRESH_NAME, '.store-grid');
        $I->dontSee(self::STALE_NAME);

        $I->amOnPage('/practice-orders?audio=all&q=' . urlencode(self::SEARCH));
        $I->seeResponseCodeIs(200);
        $I->see(self::FRESH_NAME, '.store-grid');
        $I->dontSee(self::STALE_NAME);
        $I->see('Case Order placed in the last 30', '[data-recent-case-order-note]');
    }

    /** The window composes with the letter index and the search rather than replacing them. */
    public function theWindowComposesWithTheLetterAndTheSearch(WebTester $I): void
    {
        $I->amOnPage('/order-testing?letter=Z&q=' . urlencode(self::SEARCH));
        $I->see(self::FRESH_NAME, '.store-grid');

        $I->amOnPage('/order-testing?letter=A&q=' . urlencode(self::SEARCH));
        $I->dontSee(self::FRESH_NAME);

        // Only the stale store matches the search, so nothing qualifies — and the page says why.
        $I->amOnPage('/order-testing?q=' . urlencode(self::STALE_NAME));
        $I->see('No stores match');
        $I->see('No store has a Case Order placed in the last 30 days');
    }

    /** Only the cards are narrowed: a store with older orders still opens from its own address. */
    public function aStoreWithOnlyOlderOrdersStillOpensDirectly(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STALE);
        $I->seeResponseCodeIs(200);
        $I->see(self::STALE_NAME);

        $I->amOnPage('/practice-orders/store/' . self::STALE);
        $I->seeResponseCodeIs(200);
    }

    /** The Audio-to-Text store picker shares the code and is not narrowed. */
    public function theAudioToTextPickerStillListsEveryStore(WebTester $I): void
    {
        $I->amOnPage('/admin/order58/store-audio?q=' . urlencode(self::SEARCH));
        $I->seeResponseCodeIs(200);
        $I->see(self::FRESH_NAME, '.store-grid');
        $I->see(self::STALE_NAME, '.store-grid');
        $I->dontSeeElement('[data-recent-case-order-note]');
    }

    // ---------------------------------------------------------------- fixtures

    private function seedStore(int $sourceId, string $name): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => $name,
            'active' => 1,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => $name,
            'slug' => 'kf-recent-window-' . $sourceId,
            'source_system' => 'order58',
            'source_store_id' => $sourceId,
            'source_name' => $name,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** Synchronised now, whatever its age: only the creation time may decide. */
    private function seedOrder(int $store, int $order, int $createdAt): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $order,
            'source_created_at' => $createdAt,
            'payload_json' => '{}',
            'content_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** Scoped to this suite's own administrator and stores, never a blanket delete. */
    private function cleanup(): void
    {
        $stores = [self::FRESH, self::STALE];

        $this->connection->createCommand()->delete('{{%order58_orders}}', ['account_id' => $stores])->execute();
        $this->connection->createCommand()->delete('{{%knowledge_bases}}', ['source_store_id' => $stores])->execute();
        $this->connection->createCommand()->delete('{{%order58_stores}}', ['source_id' => $stores])->execute();

        $adminIds = (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::USERNAME])->column();

        if ($adminIds !== []) {
            $this->connection->createCommand()->delete('{{%admin_users}}', ['id' => $adminIds])->execute();
        }
    }
}
