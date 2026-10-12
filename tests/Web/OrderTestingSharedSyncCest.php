<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Integration\Order58Recording\FixtureAvailability;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function gmdate;
use function str_repeat;

/**
 * One "Sync demo orders" button on three pages.
 *
 * ## What is really being guarded
 *
 * That it is genuinely **one** implementation. Three buttons that each imported demo orders their own
 * way would drift, and the second one written would be the one that forgot the lock. The proof here is
 * structural rather than asserted on a message: all three forms post to the same endpoint, so there is
 * only one importer to get right.
 *
 * And the return contract. The whole reason the field exists is that an operator who syncs from a
 * store page should not be dumped on the picker — but a field naming where to go next is also how an
 * open redirect gets built, so the refusal cases matter more than the happy ones.
 *
 * ## Why `source=fixtures` on the recordings page
 *
 * `load=1` makes that page ask the provider for a day's calls. The fixture source is the project's own
 * switch for exactly this, so the page can be loaded with calls on it without a request leaving the
 * machine.
 */
final class OrderTestingSharedSyncCest
{
    private const ADMIN = '__kf_shared_sync_admin__';
    private const PASSWORD = 'SharedSyncPassw0rd!secure';

    private const ENDPOINT = '/order-testing/sync-demo-orders';
    private const FORM = 'form[action="/order-testing/sync-demo-orders"]';

    private const STORE_ID = 987655950;
    private const STORE_NAME = 'Shared Sync Fixture Store';
    private const COMPANY = 'KFSS';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->seedStore();
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ the button is on all three

    public function thePickerStillHasTheButton(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/order-testing');

        $I->seeElement(self::FORM);
        $I->see('Sync demo orders');
    }

    public function theStorePageHasTheButton(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $I->seeElement(self::FORM);
        $I->see('Sync demo orders');
    }

    public function theCallRecordingsPageHasTheButton(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/admin/order58/call-recordings');

        $I->seeElement(self::FORM);
        $I->see('Sync demo orders');
    }

    /**
     * The same backend, proved by address.
     *
     * Three forms, one endpoint — so there is one importer, one lock and one set of rules about what a
     * malformed document does.
     */
    public function allThreeButtonsPostToTheSameEndpoint(WebTester $I): void
    {
        $this->signIn($I);

        foreach (['/order-testing', '/order-testing/store/' . self::STORE_ID, '/admin/order58/call-recordings'] as $page) {
            $I->amOnPage($page);
            $I->seeElement('form[action="' . self::ENDPOINT . '"][method="post"]');
        }
    }

    /** Every one of them carries a token, because every one of them does work. */
    public function allThreeButtonsCarryACsrfToken(WebTester $I): void
    {
        $this->signIn($I);

        foreach (['/order-testing', '/order-testing/store/' . self::STORE_ID, '/admin/order58/call-recordings'] as $page) {
            $I->amOnPage($page);
            $I->seeElement(self::FORM . ' input[name="_csrf"]');
        }
    }

    /** The same double-submit affordance on each, so the pages behave alike while a sync runs. */
    public function allThreeButtonsShowTheSameBusyAffordance(WebTester $I): void
    {
        $this->signIn($I);

        foreach (['/order-testing', '/order-testing/store/' . self::STORE_ID, '/admin/order58/call-recordings'] as $page) {
            $I->amOnPage($page);
            $I->seeElement(self::FORM . ' button[data-busy-label="Syncing…"]');
        }
    }

    // ------------------------------------------------------------------ where it returns to

    /** Unchanged: the picker posts no `return` at all, and the default is the picker. */
    public function syncingFromThePickerReturnsToThePicker(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/order-testing');
        $I->dontSeeElement(self::FORM . ' input[name="return"]');

        $I->submitForm(self::FORM, []);

        $I->seeCurrentUrlEquals('/order-testing');
        $I->see('Demo orders');
    }

    public function syncingFromTheStorePageReturnsToThatStore(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $I->seeElement(self::FORM . ' input[name="return"][value="order-testing.store"]');
        $I->seeElement(self::FORM . ' input[name="sourceId"][value="' . self::STORE_ID . '"]');

        $I->submitForm(self::FORM, []);

        $I->seeCurrentUrlEquals('/order-testing/store/' . self::STORE_ID);
        $I->see('Demo orders');
    }

    public function syncingFromTheCallRecordingsPageReturnsThere(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/admin/order58/call-recordings');
        $I->seeElement(self::FORM . ' input[name="return"][value="order58.call-recordings"]');

        $I->submitForm(self::FORM, []);

        $I->seeInCurrentUrl('/admin/order58/call-recordings');
        $I->see('Demo orders');
    }

    /** The store and the day the operator had loaded survive the round trip. */
    public function theCallRecordingsFiltersSurviveTheSync(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(
            '/admin/order58/call-recordings?store=' . self::STORE_ID
            . '&load=1&date=2026-10-05&source=' . FixtureAvailability::FIXTURE,
        );

        $I->submitForm(self::FORM, []);

        $I->seeInCurrentUrl('store=' . self::STORE_ID);
        $I->seeInCurrentUrl('date=2026-10-05');
        $I->seeInCurrentUrl('load=1');
        $I->seeInCurrentUrl('source=' . FixtureAvailability::FIXTURE);
    }

    /** Without a loaded day there is no `load` to carry, and none is invented. */
    public function anUnloadedRecordingsPageCarriesNoLoadFlag(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/admin/order58/call-recordings?store=' . self::STORE_ID);

        $I->dontSeeElement(self::FORM . ' input[name="load"]');
    }

    // ------------------------------------------------------------------ the return field is not trusted

    /**
     * A crafted `return` cannot send anybody off this site.
     *
     * Route names, not URLs: the value never reaches a `Location` header, so the worst case is the
     * picker rather than a convincing copy of it somewhere else.
     *
     * @dataProvider hostileReturns
     */
    public function aCraftedReturnValueFallsBackToThePicker(WebTester $I, \Codeception\Example $example): void
    {
        $this->signIn($I);
        $I->amOnPage('/order-testing');

        $I->submitForm(self::FORM, ['return' => (string) $example['value']]);

        $I->seeCurrentUrlEquals('/order-testing');
    }

    /** @return iterable<array{value: string}> */
    protected function hostileReturns(): iterable
    {
        yield ['value' => 'https://evil.example.com/'];
        yield ['value' => '//evil.example.com'];
        yield ['value' => '/admin/order58/orders'];
        yield ['value' => 'order58.orders'];
        yield ['value' => 'order-testing.demo-compare'];
        yield ['value' => ''];
    }

    /**
     * An allow-listed page whose argument is missing or malformed falls back rather than failing.
     *
     * The sync itself succeeded; refusing to render a result because a hidden field was tampered with
     * would punish the operator for somebody else's edit.
     *
     * @dataProvider badStoreIds
     */
    public function aStoreReturnWithoutAUsableIdFallsBackToThePicker(WebTester $I, \Codeception\Example $example): void
    {
        $this->signIn($I);
        $I->amOnPage('/order-testing');

        $I->submitForm(self::FORM, ['return' => 'order-testing.store', 'sourceId' => (string) $example['value']]);

        $I->seeCurrentUrlEquals('/order-testing');
    }

    /** @return iterable<array{value: string}> */
    protected function badStoreIds(): iterable
    {
        yield ['value' => ''];
        yield ['value' => '0'];
        yield ['value' => '-1'];
        yield ['value' => 'abc'];
        yield ['value' => '987655950abc'];
    }

    /** Still a POST with a token: without one, nothing runs. */
    public function theSyncPostRequiresCsrf(WebTester $I): void
    {
        $this->signIn($I);

        $I->sendAjaxPostRequest(self::ENDPOINT, ['return' => 'order-testing.store', 'sourceId' => (string) self::STORE_ID]);

        $I->dontSee('Demo orders synced');
    }

    public function aGuestCannotSyncDemoOrders(WebTester $I): void
    {
        $I->amOnPage('/order-testing');

        $I->seeCurrentUrlEquals('/login');
        $I->dontSeeElement(self::FORM);
    }

    // ------------------------------------------------------------------ the pages still work

    /** The new button must not have displaced what these pages are for. */
    public function theStorePageStillRendersItsOwnActions(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $I->see(self::STORE_NAME);
        $I->see('All stores');
        $I->seeElement('.page-header__actions');
    }

    public function theCallRecordingsPageStillRendersItsOwnActions(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/admin/order58/call-recordings');

        $I->see('Order58 Call Recordings');
        $I->see('View Download History');
        $I->see('Sync Order58 Orders');
    }

    // ------------------------------------------------------------------ harness

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
            'company' => self::COMPANY,
            'active' => 1,
            'snapshot_json' => '{"id":' . self::STORE_ID . ',"fields":{}}',
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        // Both pages resolve a store through `knowledge_bases`: `source_active` there is the
        // authoritative flag, and seeding only the mirror sends every store-page visit to the picker.
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => self::STORE_NAME,
            'slug' => 'kf-shared-sync-' . self::STORE_ID,
            'source_system' => 'order58',
            'source_store_id' => self::STORE_ID,
            'source_name' => self::STORE_NAME,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** Scoped to this suite's own fixtures, never a blanket delete. */
    private function cleanup(): void
    {
        $adminIds = (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::ADMIN])->column();

        if ($adminIds !== []) {
            $this->connection->createCommand()
                ->delete('{{%admin_users}}', ['id' => $adminIds])->execute();
        }

        $this->connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['source_store_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => self::STORE_ID])->execute();
    }
}
