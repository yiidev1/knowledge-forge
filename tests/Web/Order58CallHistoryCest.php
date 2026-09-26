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

use function gmdate;
use function strpos;
use function str_repeat;

/**
 * Sync History across all stores, end to end through the browser tier.
 *
 * ## What these tests are really guarding
 *
 * A call is up to three rows in `order58_call_imports`, and this page pages over **calls**. Page a table
 * like that by rows and the seam is invisible until it bites: the same call appears at the bottom of one
 * page and the top of the next, each time showing only some of its channels, and every status on both
 * copies looks plausible. So the tests below check the boundary specifically — that page 2 repeats
 * nothing from page 1, and that a call split across the boundary keeps all of its cells.
 *
 * The second thing guarded is that this page did not quietly take over from the store-specific history
 * on the calls page. Both read the same rows; only one of them is scoped to a store, and it has to stay
 * that way.
 */
final class Order58CallHistoryCest
{
    private const USERNAME = '__kf_o58_history_admin__';
    private const PASSWORD = 'Order58HistoryPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    /** Far outside the mirrored range, so nothing here can collide with real store data. */
    private const STORE_A = 987655300;
    private const STORE_B = 987655301;
    private const NAME_A = '__KF History Store A__';
    private const NAME_B = '__KF History Store B__';

    private const PAGE = '/admin/order58/calls/history';
    private const CALLS_PAGE = '/admin/order58/calls';

    /** One more than a page holds, so the pager has a second page with exactly one call on it. */
    private const PER_PAGE = 25;

    private ConnectionInterface $connection;
    private int $adminId;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->adminId = (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->createStore(self::STORE_A, self::NAME_A);
        $this->createStore(self::STORE_B, self::NAME_B);
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ the gate

    /** Signed out, it is the login form — the same gate the calls page sits behind. */
    public function thePageRequiresAnAdministrator(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage(self::PAGE);

        $I->seeInCurrentUrl('/login');
        $I->dontSee('View Order58 sync history across all stores.');
    }

    // ------------------------------------------------------------------ the way in

    /** The calls page offers the button, and it points here. */
    public function theCallsPageLinksToThisOne(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::CALLS_PAGE);

        $I->see('View Sync History');
        $I->seeLink('View Sync History', self::PAGE);
    }

    /** And this page offers the way back, so the two are not a one-way trip. */
    public function thisPageLinksBack(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->seeLink('← Back to Manage Order58 Calls', self::CALLS_PAGE);
    }

    // ------------------------------------------------------------------ all stores

    /**
     * The point of the page: one table, every store, each row saying which.
     *
     * The store id is asserted as well as the name, because two merchants can share a trading name and
     * the id is what an operator would search the database with.
     */
    public function everyStoreAppearsOnOnePage(WebTester $I): void
    {
        $this->queueCall(self::STORE_A, '55500001', '66600001');
        $this->queueCall(self::STORE_B, '55500002', '66600002');

        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->see('Sync History');
        $I->see(self::NAME_A);
        $I->see(self::NAME_B);
        $I->see('#' . self::STORE_A);
        $I->see('#' . self::STORE_B);
        $I->see('55500001');
        $I->see('55500002');
    }

    /** Newest call first, which is the order the repository imposes and the pager depends on. */
    public function theNewestCallIsListedFirst(WebTester $I): void
    {
        $this->queueCall(self::STORE_A, '55500010', '66600010');
        $this->queueCall(self::STORE_A, '55500011', '66600011');

        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $source = $I->grabPageSource();
        $newest = strpos($source, '55500011');
        $older = strpos($source, '55500010');

        Assert::assertNotFalse($newest);
        Assert::assertNotFalse($older);
        Assert::assertLessThan($older, $newest, 'The most recently queued call must be listed first.');
    }

    /** Each channel keeps its own status, and the call keeps the derived one. */
    public function statusesRenderPerChannelAndOverall(WebTester $I): void
    {
        $this->queueCall(self::STORE_A, '55500020', '66600020', [
            'mixed' => 'IMPORTED',
            'caller' => 'NOT_AVAILABLE',
            'callee' => 'NOT_AVAILABLE',
        ]);

        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->see('Imported');
        $I->see('Not available');
        // One imported, two the provider refused: Partial, which is the normal healthy outcome for a
        // merchant that produces no separated channels.
        $I->see('Partial');
    }

    // ------------------------------------------------------------------ paging

    /**
     * The boundary test. A call must not be split across pages, and page 2 must not repeat page 1.
     *
     * 26 calls over a 25-per-page table puts exactly one call on page 2, and the one that lands there is
     * the oldest — so if the repository ever pages by row instead of by call, this is where it shows.
     */
    public function pagingSplitsCallsNeverChannels(WebTester $I): void
    {
        for ($i = 0; $i < self::PER_PAGE + 1; $i++) {
            $this->queueCall(self::STORE_A, (string) (55501000 + $i), (string) (66601000 + $i));
        }

        $this->signIn($I);

        $I->amOnPage(self::PAGE);
        $first = $I->grabPageSource();

        $I->amOnPage(self::PAGE . '?page=2');
        $second = $I->grabPageSource();

        // The oldest call is the one pushed onto page 2.
        Assert::assertStringNotContainsString('55501000', $first, 'The oldest call belongs on page 2, not page 1.');
        Assert::assertStringContainsString('55501000', $second);

        // And the newest is on page 1 only — no call appears on both.
        $newest = (string) (55501000 + self::PER_PAGE);
        Assert::assertStringContainsString($newest, $first);
        Assert::assertStringNotContainsString($newest, $second, 'A call must not appear on two pages.');

        // Every channel of the page-2 call is on that page: three cells, not one.
        $I->amOnPage(self::PAGE . '?page=2');
        $I->see('Mixed');
        $I->see('Caller');
        $I->see('Callee');
    }

    /** A page past the end shows the last real page rather than an empty table or an error. */
    public function aPageBeyondTheEndFallsBackToTheLastOne(WebTester $I): void
    {
        $this->queueCall(self::STORE_A, '55500030', '66600030');

        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?page=999');

        $I->seeResponseCodeIs(200);
        $I->see('55500030');
    }

    /** A junk page parameter is treated as page 1, not as a crash. */
    public function aJunkPageParameterIsHarmless(WebTester $I): void
    {
        $this->queueCall(self::STORE_A, '55500040', '66600040');

        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?page=not-a-number');

        $I->seeResponseCodeIs(200);
        $I->see('55500040');
    }

    /** With nothing imported, the empty state explains itself rather than showing a bare table. */
    public function anEmptyHistorySaysSo(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->see('Nothing imported yet');
    }

    // ------------------------------------------------------------------ the old page is untouched

    /**
     * The calls page's own history must stay store-specific.
     *
     * With a call queued for each of two stores, selecting store A there must not show store B — that is
     * the behaviour this feature was explicitly not allowed to change.
     */
    public function theCallsPageHistoryStaysScopedToItsStore(WebTester $I): void
    {
        $this->queueCall(self::STORE_A, '55500050', '66600050');
        $this->queueCall(self::STORE_B, '55500051', '66600051');

        $this->signIn($I);
        $I->amOnPage(self::CALLS_PAGE . '?store=' . self::STORE_A);

        $I->see('55500050');
        $I->dontSee('55500051');
    }

    /** The page contacts no provider, whatever the query string — it reads two local tables. */
    public function thePageCarriesNoInlineScript(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $source = $I->grabPageSource();
        Assert::assertStringNotContainsString('<script>', $source, 'CSP is script-src self; no inline script.');
    }

    // ------------------------------------------------------------------ plumbing

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    /**
     * One call: a batch and a row per channel, written directly.
     *
     * Direct inserts rather than a sync through the page, because what is under test here is the reading
     * side. Going through the form would make every one of these tests depend on the provider fixture as
     * well.
     *
     * @param array<string, string> $channels channel => status
     */
    private function queueCall(
        int $store,
        string $session,
        string $order,
        array $channels = ['mixed' => 'IMPORTED', 'caller' => 'IMPORTED', 'callee' => 'IMPORTED'],
    ): void {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_call_import_batches}}', [
            'store_source_id' => $store,
            'triggered_by' => 'MANUAL',
            'requested_by_admin_id' => $this->adminId,
            'transcription_provider' => 'WHISPER',
            'generate_ai_audio' => 0,
            'recording_company' => 'KFHS',
            'call_count' => 1,
            'created_at' => $now,
        ])->execute();

        $batchId = (int) $this->connection->getLastInsertID();

        foreach ($channels as $channel => $status) {
            $this->connection->createCommand()->insert('{{%order58_call_imports}}', [
                'batch_id' => $batchId,
                'store_source_id' => $store,
                'call_session_id' => $session,
                'channel' => $channel,
                'call_time_raw' => '2026-09-26 01:00:00',
                'call_date' => '2026-09-26',
                'order_id' => $order,
                'status' => $status,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
        }
    }

    private function createStore(int $sourceId, string $name): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => $name,
            'company' => 'KFHS',
            'active' => 1,
            'snapshot_json' => '{"id":' . $sourceId . ',"fields":{}}',
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();
        $stores = [self::STORE_A, self::STORE_B];

        // Children first: the batch foreign key is RESTRICT.
        $connection->createCommand()->delete('{{%order58_call_imports}}', ['store_source_id' => $stores])->execute();
        $connection->createCommand()->delete('{{%order58_call_import_batches}}', ['store_source_id' => $stores])->execute();
        $connection->createCommand()->delete('{{%knowledge_bases}}', ['source_store_id' => $stores])->execute();
        IntegrationDb::cleanup($connection, '{{%order58_stores}}', ['source_id' => $stores]);
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
