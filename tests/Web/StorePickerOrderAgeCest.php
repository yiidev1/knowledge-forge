<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_keys;
use function gmdate;
use function hash;
use function str_repeat;
use function trim;
use function urlencode;

/**
 * Manage Orders and Practice Orders list a store whatever the age of its Case Orders: there is no
 * date window (the 30-day one was removed on 2026-11-06), and the page no longer mentions one. Each data
 * source chip decides alone, as it did before that window was added.
 *
 * Five fixture stores whose only Case Order — with one call recording, so both pages' default data
 * sources list it — was placed today, 15 days, 45 days, six months and two years ago, and a sixth with
 * no Case Order and no audio at all, which only "All stores" lists. The search narrows each page to
 * these stores, so the real mirror's stores cannot affect the result.
 */
final class StorePickerOrderAgeCest
{
    private const USERNAME = '__kf_order_age_admin__';
    private const PASSWORD = 'OrderAgePassw0rd!secure';
    private const SEARCH = 'ZZ Order Age';

    /** store source id => [name, order id, how long ago the order was placed] */
    private const AGED = [
        987655965 => ['ZZ Order Age Today', 16930001, '-1 hour'],
        987655966 => ['ZZ Order Age Fifteen Days', 16930002, '-15 days'],
        987655973 => ['ZZ Order Age Forty Five Days', 16930003, '-45 days'],
        987655974 => ['ZZ Order Age Six Months', 16930004, '-6 months'],
        987655975 => ['ZZ Order Age Two Years', 16930005, '-2 years'],
    ];
    private const NO_ORDERS = 987655976;
    private const NO_ORDERS_NAME = 'ZZ Order Age No Orders';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));

        foreach (self::AGED as $store => [$name, $order, $ago]) {
            $this->seedStore($store, $name);
            $this->seedOrderWithRecording($store, $order, (new DateTimeImmutable($ago))->getTimestamp());
        }

        $this->seedStore(self::NO_ORDERS, self::NO_ORDERS_NAME);

        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function manageOrdersListsStoresWhateverTheAgeOfTheirOrders(WebTester $I): void
    {
        $this->seeEveryAgedStoreAndNoWindow($I, '/order-testing');
    }

    public function practiceOrdersListsThemToo(WebTester $I): void
    {
        $this->seeEveryAgedStoreAndNoWindow($I, '/practice-orders');
    }

    /** "All stores" lists every store again, one with no Case Order included. */
    public function allStoresIncludesAStoreWithoutCaseOrders(WebTester $I): void
    {
        foreach (['/order-testing', '/practice-orders'] as $picker) {
            $I->amOnPage($picker . '?audio=all&q=' . urlencode(self::SEARCH));
            $I->seeResponseCodeIs(200);
            $I->see(self::NO_ORDERS_NAME, '.store-grid');
            $I->see('ZZ Order Age Two Years', '.store-grid');
        }
    }

    /** Each page's Case Order chip — its default — lists stores with Case Orders of any age, and only them. */
    public function theCaseOrderChipsListOnlyStoresWithCaseOrders(WebTester $I): void
    {
        foreach (['/order-testing' => ['', '&audio=case-orders'], '/practice-orders' => ['', '&audio=case-recordings']] as $picker => $sources) {
            foreach ($sources as $source) {
                $I->amOnPage($picker . '?q=' . urlencode(self::SEARCH) . $source);
                $I->seeResponseCodeIs(200);
                $I->see('ZZ Order Age Two Years', '.store-grid');
                $I->dontSee(self::NO_ORDERS_NAME);
            }
        }
    }

    /** "Uploaded audio" keeps its own rule: none of these stores has uploaded audio, so none is listed. */
    public function uploadedAudioKeepsItsOwnRule(WebTester $I): void
    {
        foreach (['/order-testing', '/practice-orders'] as $picker) {
            $I->amOnPage($picker . '?audio=with&q=' . urlencode(self::SEARCH));
            $I->seeResponseCodeIs(200);
            $I->see('No stores match');
            $I->dontSee('ZZ Order Age Two Years');
            $I->dontSee(self::NO_ORDERS_NAME);
        }
    }

    /** The letter index and the search narrow exactly as before. */
    public function theLetterAndTheSearchStillNarrow(WebTester $I): void
    {
        foreach (['/order-testing', '/practice-orders'] as $picker) {
            $I->amOnPage($picker . '?letter=Z&q=' . urlencode(self::SEARCH));
            $I->see('ZZ Order Age Two Years', '.store-grid');

            $I->amOnPage($picker . '?letter=A&q=' . urlencode(self::SEARCH));
            $I->dontSee('ZZ Order Age Two Years');

            $I->amOnPage($picker . '?q=' . urlencode('ZZ Order Age Six Months'));
            $I->see('ZZ Order Age Six Months', '.store-grid');
            $I->dontSee('ZZ Order Age Today');
        }
    }

    /** The two-year-old store's card counts its order on both pages, like any other. */
    public function anOldStoresCardCountsItsOrder(WebTester $I): void
    {
        foreach (['/order-testing', '/practice-orders'] as $picker) {
            $I->amOnPage($picker . '?q=' . urlencode('ZZ Order Age Two Years'));
            $I->see('ZZ Order Age Two Years', '.store-card__name');
            Assert::assertSame(
                '1',
                trim((string) $I->grabTextFrom('.store-card .store-card__metric:first-child .store-card__metric-value')),
                $picker,
            );
        }
    }

    /** The Audio-to-Text store picker shares the code and still lists every store, order or not. */
    public function theAudioToTextPickerIsUnchanged(WebTester $I): void
    {
        $I->amOnPage('/admin/order58/store-audio?audio=all&q=' . urlencode(self::SEARCH));
        $I->seeResponseCodeIs(200);
        $I->see('ZZ Order Age Two Years', '.store-grid');
        $I->see(self::NO_ORDERS_NAME, '.store-grid');
    }

    private function seeEveryAgedStoreAndNoWindow(WebTester $I, string $picker): void
    {
        $I->amOnPage($picker . '?q=' . urlencode(self::SEARCH));
        $I->seeResponseCodeIs(200);

        foreach (self::AGED as [$name]) {
            $I->see($name, '.store-grid');
        }

        $I->dontSee(self::NO_ORDERS_NAME);
        $I->dontSee('placed in the last 30 days');
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
            'slug' => 'kf-order-age-' . $sourceId,
            'source_system' => 'order58',
            'source_store_id' => $sourceId,
            'source_name' => $name,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** Synchronised now, whatever its age, with one current call recording that is not measured yet. */
    private function seedOrderWithRecording(int $store, int $order, int $createdAt): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $order,
            'source_created_at' => $createdAt,
            'payload_json' => '{}',
            'content_hash' => hash('sha256', (string) $order),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $session = (string) ($order - 16_000_000);
        $this->connection->createCommand()->insert('{{%order58_call_sessions}}', [
            'store_source_id' => $store,
            'source_order_id' => $order,
            'call_session_id' => $session,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        $sessionRow = (int) $this->connection->getLastInsertID();

        $url = 'https://order58.xrainbow.com/api/external/recording/fetch/' . $session
            . '?time=' . gmdate('Y-m-d', $createdAt) . '&company=SWCC&name=' . $order;
        $this->connection->createCommand()->insert('{{%order58_call_recordings}}', [
            'session_id' => $sessionRow,
            'position' => 0,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** Scoped to this suite's own administrator and stores; never a blanket delete. */
    private function cleanup(): void
    {
        $stores = [...array_keys(self::AGED), self::NO_ORDERS];

        // Sessions and recordings cascade from the orders.
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
