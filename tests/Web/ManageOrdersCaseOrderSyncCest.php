<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;

use function count;
use function gmdate;
use function time;

/**
 * "Sync Order58 Case Orders" on Manage Orders: the Call Recordings page's control, not a second one.
 *
 * ## What is really being guarded
 *
 * That there is ONE synchronisation. Both pages render the same trigger and dialog partials, post to
 * the same endpoint and read the same coordinator, so the proof here is structural — the same form,
 * the same address, the same refusal — rather than a message that two implementations could both
 * happen to print. And that the button was ADDED: everything the header already offered stays.
 *
 * **No test here posts a synchronisation that could reach the provider.** Every POST takes the
 * `reimport` branch with a run that cannot exist, which answers locally — the same discipline
 * `Order58CallRecordingsCest` keeps. What a real run does is covered without a socket in
 * `tests/Unit/Order58/CaseOrderSyncServiceTest.php`.
 */
final class ManageOrdersCaseOrderSyncCest
{
    private const USERNAME = '__kf_mo_case_sync_admin__';
    private const PASSWORD = 'ManageOrdersCaseSyncPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    /** The zone the coordinator counts business days in, as the Call Recordings suite seeds it. */
    private const CASE_ORDER_ZONE = 'America/New_York';

    private const PICKER = '/order-testing';
    private const RECORDINGS = '/admin/order58/call-recordings';
    private const ENDPOINT = '/admin/order58/case-orders/sync';

    /** A run id that cannot exist, so a POST takes the local re-import branch and asks nobody. */
    private const MISSING_RUN = '999999999';

    private ConnectionInterface $connection;

    /** @var list<int> run rows this suite wrote, removed by id and by nothing broader */
    private array $createdRunIds = [];

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ the header

    /** One button more; the two that were there are untouched. */
    public function theHeaderAddsCaseOrderSyncBesideWhatItHad(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PICKER);

        $I->see('Sync Order58 Case Orders', '.page-header__actions');

        $I->see('Sync demo orders', '.page-header__actions');
        $I->seeElement('.page-header__actions form[action="/order-testing/sync-demo-orders"][method="post"]');
        $I->see('Transcription settings', '.page-header__actions');
    }

    /** Secondary, like the rest of this header. */
    public function theButtonMatchesTheHeadersStyle(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PICKER);

        $I->seeElement('.page-header__actions a.btn.btn--secondary[data-o58-case-order-open]');
        $I->dontSeeElement('.page-header__actions a.btn--primary[data-o58-case-order-open]');
    }

    // ------------------------------------------------------------------ one dialog, one endpoint

    /**
     * Both pages render the same dialog, posting to the same endpoint and polling the same status.
     *
     * @dataProvider bothPages
     */
    public function bothPagesRenderTheSameDialog(WebTester $I, \Codeception\Example $example): void
    {
        $this->signIn($I);
        $I->amOnPage((string) $example['page']);

        $form = 'dialog[data-o58-case-order-dialog] form[action="' . self::ENDPOINT . '"][method="post"]';

        $I->seeElement($form . ' input[name="_csrf"]');
        $I->seeElement($form . ' button[data-o58-case-order-start]');
        $I->seeElement('[data-o58-case-order-panel][data-o58-case-order-status="/admin/order58/case-orders/status"]');
        $I->see('Synchronise Order58 case orders?');
        // Closed until asked for.
        $I->dontSeeElement('dialog[data-o58-case-order-dialog][open]');
    }

    /** @return iterable<array{page: string}> */
    protected function bothPages(): iterable
    {
        yield ['page' => self::PICKER];
        yield ['page' => self::RECORDINGS];
    }

    /** The only differences are where a scriptless press returns, and whether closing reloads. */
    public function onlyTheReturnAndTheRefreshDiffer(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER);
        $I->seeElement('dialog[data-o58-case-order-dialog][data-o58-case-order-refresh]');
        $I->seeElement('dialog[data-o58-case-order-dialog] input[name="return"][value="order-testing"]');

        // Call Recordings is exactly as it was: no return field, no reload, its own primary button.
        $I->amOnPage(self::RECORDINGS);
        $I->dontSeeElement('dialog[data-o58-case-order-dialog][data-o58-case-order-refresh]');
        $I->dontSeeElement('dialog[data-o58-case-order-dialog] input[name="return"]');
        $I->seeElement('.page-header__actions a.btn.btn--primary[data-o58-case-order-open]');
    }

    /** The scriptless way in keeps the search and letter the operator was looking at, and so does Close. */
    public function theConfirmationOpensWithoutScriptAndKeepsTheFilters(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PICKER . '?q=zz&letter=B');

        $I->seeElement('a[data-o58-case-order-open][href="/order-testing?q=zz&letter=B&confirm-case-orders=1"]');

        $I->amOnPage(self::PICKER . '?q=zz&letter=B&confirm-case-orders=1');

        $I->seeElement('dialog[data-o58-case-order-dialog][open]');
        $I->seeElement('[data-o58-case-order-cancel][href="/order-testing?q=zz&letter=B"]');
    }

    // ------------------------------------------------------------------ where a press lands

    /** With no script, a press from Manage Orders comes back to Manage Orders, naming its run. */
    public function aScriptlessPressComesBackToManageOrders(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PICKER);

        $I->sendAjaxPostRequest(self::ENDPOINT, [
            '_csrf' => $this->token($I),
            'reimport' => self::MISSING_RUN,
            'return' => 'order-testing',
        ]);

        $I->seeCurrentUrlEquals(self::PICKER . '?sync-run=' . self::MISSING_RUN);
        $I->see('Manage Orders');
    }

    /** Posting no `return` — which is what Call Recordings posts — still lands on Call Recordings. */
    public function withoutAReturnThePressStillComesBackToCallRecordings(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::RECORDINGS);

        $I->sendAjaxPostRequest(self::ENDPOINT, [
            '_csrf' => $this->token($I),
            'reimport' => self::MISSING_RUN,
        ]);

        $I->seeCurrentUrlEquals(self::RECORDINGS . '?sync-run=' . self::MISSING_RUN);
    }

    /**
     * A crafted `return` cannot send anybody anywhere else: it is a route name from an allow-list.
     *
     * @dataProvider hostileReturns
     */
    public function aCraftedReturnFallsBackToCallRecordings(WebTester $I, \Codeception\Example $example): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PICKER);

        $I->sendAjaxPostRequest(self::ENDPOINT, [
            '_csrf' => $this->token($I),
            'reimport' => self::MISSING_RUN,
            'return' => (string) $example['value'],
        ]);

        $I->seeCurrentUrlEquals(self::RECORDINGS . '?sync-run=' . self::MISSING_RUN);
    }

    /** @return iterable<array{value: string}> */
    protected function hostileReturns(): iterable
    {
        yield ['value' => 'https://evil.example.com/'];
        yield ['value' => '//evil.example.com'];
        yield ['value' => '/order-testing'];
        yield ['value' => 'order-testing.store'];
        yield ['value' => 'order58.orders'];
        yield ['value' => ''];
    }

    /** The script's JSON answer is the same whichever page asked; `return` changes nothing there. */
    public function theScriptedPressAnswersJsonFromEitherPage(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PICKER);

        $I->haveHttpHeader('X-Requested-With', 'fetch');
        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest(self::ENDPOINT, [
            '_csrf' => $this->token($I),
            'reimport' => self::MISSING_RUN,
            'return' => 'order-testing',
        ]);

        $I->seeResponseCodeIs(200);
        $I->see('"success":false');
        $I->see('"hasRun":false');
    }

    // ------------------------------------------------------------------ the coordinator decides, here too

    /** A run in flight disables the button here exactly as it does on Call Recordings. */
    public function aRunInFlightDisablesTheButtonOnBothPages(WebTester $I): void
    {
        $this->seedCaseOrderRun('PROCESSING', [
            'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
        ]);

        $this->signIn($I);

        foreach ([self::PICKER, self::RECORDINGS] as $page) {
            $I->amOnPage($page);

            $I->dontSeeElement('.page-header__actions a[data-o58-case-order-open]');
            $I->seeElement('.page-header__actions span[aria-disabled="true"]');
            // And the dialog describes that run, by id, so its polling cannot drift.
            $I->seeElement('[data-o58-case-order-panel][data-o58-case-order-run="' . $this->lastSeededRunId() . '"]');
        }
    }

    /** A finished run is history, not the operation about to be confirmed — on this page as well. */
    public function aPreviousRunIsNotShownInTheConfirmation(WebTester $I): void
    {
        $this->seedCaseOrderRun('COMPLETED', [
            'completed_at' => gmdate('Y-m-d H:i:s'),
            'orders_received' => 200,
            'items_imported' => 723,
        ]);

        $this->signIn($I);
        $I->amOnPage(self::PICKER);

        $I->seeElement('[data-o58-case-order-panel][hidden]');
        $I->dontSeeElement('[data-o58-case-order-run]');
        $I->seeElement('.page-header__actions a[data-o58-case-order-open]');
    }

    // ------------------------------------------------------------------ the gate

    /** Signed out, there is no button and no dialog — only the login form. */
    public function aGuestSeesNeitherTheButtonNorTheDialog(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage(self::PICKER);

        $I->seeCurrentUrlEquals('/login');
        $I->dontSeeElement('[data-o58-case-order-dialog]');
    }

    // ------------------------------------------------------------------ harness

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    private function token(WebTester $I): string
    {
        return (string) $I->grabAttributeFrom('input[name="_csrf"]', 'value');
    }

    /** @param array<string, mixed> $values */
    private function seedCaseOrderRun(string $status, array $values = []): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_case_order_sync_runs}}', [
            'business_day' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(self::CASE_ORDER_ZONE))
                ->format('Y-m-d'),
            'timezone' => self::CASE_ORDER_ZONE,
            'status' => $status,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ] + $values)->execute();

        $id = (int) $this->connection->getLastInsertID();

        // An id that never arrives would orphan the row, and an orphaned active run holds the
        // application's one synchronisation claim until its lease expires.
        if ($id <= 0) {
            Assert::fail('Could not read the seeded run id, so this fixture would be orphaned.');
        }

        $this->createdRunIds[] = $id;
    }

    private function lastSeededRunId(): int
    {
        return $this->createdRunIds === [] ? 0 : $this->createdRunIds[count($this->createdRunIds) - 1];
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();

        // By id, and only ids this suite inserted: real synchronisation history is not ours to remove.
        if ($this->createdRunIds !== []) {
            $connection->createCommand()
                ->delete('{{%order58_case_order_sync_runs}}', ['id' => $this->createdRunIds])
                ->execute();
            $this->createdRunIds = [];
        }

        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
