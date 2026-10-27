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
use function hash;
use function str_repeat;
use function strtotime;

/**
 * The admin Case Orders page, over real HTTP. **No test here reaches the Order58 API.**
 *
 * The page is a GET that reads: nothing it can be asked for starts a synchronisation, which is the
 * rule the whole case order design turns on. The cases below prove the reads are right and that the
 * page is reachable by nobody but an administrator.
 *
 * Every fixture row is removed by the exact keys this suite wrote. Nothing is deleted by store, by
 * day or by timezone — a teardown that matched on any of those once destroyed a real synchronisation
 * record, and the rule since is that a fixture removes only what it created.
 */
final class Order58CaseOrdersAdminCest
{
    private const ADMIN = '__kf_co_admin__';
    private const PASSWORD = 'CaseOrdersPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    private const PAGE = '/admin/order58/case-orders';

    private const STORE_A = 987655960;
    private const STORE_B = 987655961;
    private const STORE_A_NAME = 'Case Orders Fixture Alpha';
    private const STORE_B_NAME = 'Case Orders Fixture Beta';

    /** Ordered on two different days, so the date filters have something to tell apart. */
    private const ORDER_RECENT = 918800001;
    private const ORDER_OLD = 918800002;
    private const ORDER_OTHER_STORE = 918800003;

    private ConnectionInterface $connection;

    /** @var list<int> run ids this suite inserted. Cleanup removes these and nothing else. */
    private array $createdRunIds = [];

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->seedStore(self::STORE_A, self::STORE_A_NAME);
        $this->seedStore(self::STORE_B, self::STORE_B_NAME);

        $this->seedOrder(self::STORE_A, self::ORDER_RECENT, '2026-10-20', 'SYNCED', '42.50000');
        $this->seedOrder(self::STORE_A, self::ORDER_OLD, '2026-09-01', 'PENDING', '7.25000');
        $this->seedOrder(self::STORE_B, self::ORDER_OTHER_STORE, '2026-10-21', 'EMPTY', '13.00000');

        $this->seedItem(self::STORE_A, self::ORDER_RECENT, 1, 'Steak & Cheese', '2');
        $this->seedItem(self::STORE_A, self::ORDER_RECENT, 2, 'Fried Wings', '3');
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------------------- the gate

    /** Signed out, it is the login form rather than a listing. */
    public function thePageRequiresAnAdministrator(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage(self::PAGE);

        $I->seeInCurrentUrl('/login');
        $I->dontSee('Case Orders', 'h1');
    }

    /** The detail page is behind the same gate; a known id is no way in. */
    public function theDetailPageRequiresAnAdministrator(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage(self::PAGE . '/' . self::STORE_A . '/' . self::ORDER_RECENT);

        $I->seeInCurrentUrl('/login');
    }

    /**
     * It is reachable from Call Recordings and from nowhere else.
     *
     * Deliberately absent from the sidebar: this is an administrator's diagnostic view, and putting
     * it in the navigation would show it on every page an Operation user can also reach.
     */
    public function theOnlyEntryPointIsTheCallRecordingsPage(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage('/admin/order58/call-recordings');
        $I->see('View Case Orders');
        $I->seeElement('a[href="' . self::PAGE . '"]');

        // Not in the navigation, on a page that renders the whole sidebar.
        $I->dontSeeElement('nav a[href="' . self::PAGE . '"]');
    }

    // ------------------------------------------------------------------------------ the listing

    public function theListingShowsStoredOrdersAcrossStores(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->see((string) self::ORDER_RECENT, 'tbody');
        $I->see((string) self::ORDER_OLD, 'tbody');
        $I->see((string) self::ORDER_OTHER_STORE, 'tbody');
        $I->see(self::STORE_A_NAME, 'tbody');
        $I->see(self::STORE_B_NAME, 'tbody');
    }

    /** Counts come from the item rows, not from a column somebody might forget to update. */
    public function theListingCountsItemsAndQuantities(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?q=' . self::ORDER_RECENT);

        $I->see('2', 'tbody');   // two lines
        $I->see('5', 'tbody');   // 2 + 3 quantity
        $I->see('42.50000', 'tbody');
    }

    public function theStoreFilterNarrowsTheListing(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?store=' . self::STORE_B);

        $I->see((string) self::ORDER_OTHER_STORE, 'tbody');
        $I->dontSee((string) self::ORDER_RECENT, 'tbody');
        $I->dontSee((string) self::ORDER_OLD, 'tbody');
    }

    public function theSearchFindsOneOrderByItsNumber(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?q=' . self::ORDER_OLD);

        $I->see((string) self::ORDER_OLD, 'tbody');
        $I->dontSee((string) self::ORDER_RECENT, 'tbody');
    }

    /**
     * The order date filter reads the PROVIDER's instant, not when we stored it.
     *
     * The two are weeks apart on a rolling window, and confusing them is the mistake this listing
     * exists to avoid — so both fixtures were synced today and ordered on different days.
     */
    public function theOrderDateFilterUsesTheProvidersOwnInstant(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?from=2026-10-01');

        $I->see((string) self::ORDER_RECENT, 'tbody');
        $I->dontSee((string) self::ORDER_OLD, 'tbody', 'ordered in September, so outside the range');
    }

    public function theItemStatusFilterNarrowsTheListing(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?items=PENDING');

        $I->see((string) self::ORDER_OLD, 'tbody');
        $I->dontSee((string) self::ORDER_RECENT, 'tbody');
    }

    /** No recording exists for any fixture, so "missing" shows them all and "available" shows none. */
    public function theRecordingFilterDistinguishesAvailableFromMissing(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PAGE . '?rec=no');
        $I->see((string) self::ORDER_RECENT, 'tbody');

        $I->amOnPage(self::PAGE . '?rec=yes');
        $I->dontSee((string) self::ORDER_RECENT, 'tbody');
    }

    /** Sorting is offered as links and changes the order, rather than being decoration. */
    public function theListingCanBeSortedByOrderNumber(WebTester $I): void
    {
        $this->signIn($I);
        // Scoped to one fixture store: the database holds real orders too, and a sort assertion
        // against the whole table would be asserting something about data this suite did not write.
        $scoped = self::PAGE . '?store=' . self::STORE_A . '&sort=order&dir=';

        $I->amOnPage($scoped . 'asc');
        Assert::assertStringContainsString(
            (string) self::ORDER_RECENT,
            (string) $I->grabTextFrom('tbody tr:first-child td:first-child'),
        );

        $I->amOnPage($scoped . 'desc');
        Assert::assertStringContainsString(
            (string) self::ORDER_OLD,
            (string) $I->grabTextFrom('tbody tr:first-child td:first-child'),
        );
    }

    /** Reset appears only when something is narrowing, and clears every filter. */
    public function resetIsOfferedOnlyWhenFiltered(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PAGE);
        $I->dontSee('Reset');

        $I->amOnPage(self::PAGE . '?store=' . self::STORE_A);
        $I->see('Reset');
    }

    /** A filter that matches nothing says so, rather than rendering an empty table. */
    public function aFilterMatchingNothingSaysSo(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?q=999999999');

        $I->see('No case orders match');
    }

    // ------------------------------------------------------------------------------- the detail

    public function theDetailPageShowsTheOrderAndItsItems(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '/' . self::STORE_A . '/' . self::ORDER_RECENT);

        $I->see('Case Order #' . self::ORDER_RECENT, 'h1');
        $I->see(self::STORE_A_NAME);
        $I->see('Steak & Cheese');
        $I->see('Fried Wings');
        $I->see('42.50000');
    }

    /** The pair is the identity: another store's id must not resolve this order. */
    public function theDetailPageWillNotShowAnOrderUnderAnotherStore(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '/' . self::STORE_B . '/' . self::ORDER_RECENT);

        $I->seeResponseCodeIsClientError();
    }

    /** The raw provider document is never rendered, redacted or not. */
    public function theDetailPageDoesNotRenderTheStoredPayload(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '/' . self::STORE_A . '/' . self::ORDER_RECENT);

        $I->dontSee('payload_json');
        $I->dontSee('card_num');
        $I->dontSee('"cc"');
    }

    // ------------------------------------------------------------------- the filters that went

    /**
     * Item status, Recording and Demo order are no longer offered as controls.
     *
     * Asserted by element id rather than by label text: "Recording" and "Demo" are still column
     * headings in the table below, so a text assertion would pass or fail for the wrong reason.
     */
    public function theRetiredFiltersAreNoLongerOffered(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->dontSeeElement('#co-items');
        $I->dontSeeElement('#co-rec');
        $I->dontSeeElement('#co-demo');

        // The ones that stayed, so this cannot pass by the form having disappeared entirely.
        $I->seeElement('#co-store');
        $I->seeElement('#co-q');
    }

    /**
     * A bookmark that still carries one of them keeps working.
     *
     * The controls went; the query did not. Someone's saved URL should narrow the list as it always
     * did rather than silently showing everything, and `Reset filters` is still the way out.
     */
    public function aRetiredFilterStillWorksFromASavedUrl(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?rec=no');

        $I->see('Reset filters');
    }

    // --------------------------------------------------------------------------- the View dialog

    /** View is a real link to the detail page, which is what makes the dialog an upgrade. */
    public function theViewControlIsStillALinkToTheDetailPage(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->seeElement('a[data-o58-co-view]');
        $I->seeInSource('href="' . self::PAGE . '/' . self::STORE_A . '/' . self::ORDER_RECENT . '"');
    }

    /** The fragment is the order's facts with no page around them. */
    public function theDetailFragmentIsTheBodyWithoutTheLayout(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '/' . self::STORE_A . '/' . self::ORDER_RECENT . '?fragment=1');

        // The facts are there…
        $I->see('Steak & Cheese');
        $I->see('42.50000');

        // …and none of the chrome the page would have wrapped round them.
        $I->dontSeeInSource('<html');
        $I->dontSeeInSource('<nav');
        $I->dontSee('Back to Case Orders');
    }

    /** The fragment is behind the same gate as the page it is cut from. */
    public function theDetailFragmentRequiresAnAdministrator(WebTester $I): void
    {
        $I->amOnPage(self::PAGE . '/' . self::STORE_A . '/' . self::ORDER_RECENT . '?fragment=1');

        $I->dontSee('Steak & Cheese');
    }

    /** The identity is the pair here too — one action, so it cannot be enforced in only one branch. */
    public function theDetailFragmentWillNotShowAnOrderUnderAnotherStore(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '/' . self::STORE_B . '/' . self::ORDER_RECENT . '?fragment=1');

        $I->seeResponseCodeIsClientError();
    }

    /** The payload stays unprinted on this path as well. */
    public function theDetailFragmentDoesNotRenderTheStoredPayload(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '/' . self::STORE_A . '/' . self::ORDER_RECENT . '?fragment=1');

        $I->dontSee('payload_json');
        $I->dontSee('card_num');
    }

    // ------------------------------------------------------------------------------ the history

    /**
     * Several runs on one day are several rows.
     *
     * This is what the run-keyed schema bought: the old unique-per-day index made a second row on the
     * same date impossible, so a morning and an afternoon synchronisation could not both be recorded.
     */
    public function theHistoryShowsSeveralRunsOnTheSameDay(WebTester $I): void
    {
        $morning = $this->seedRun('2099-04-01', 'COMPLETED', 'load-calls', ['orders_received' => 4]);
        $afternoon = $this->seedRun('2099-04-01', 'COMPLETED', 'manual', ['orders_received' => 10]);

        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->see('Synchronisation history');
        $I->see('#' . $morning);
        $I->see('#' . $afternoon);
        $I->see('load-calls');
        $I->see('manual');
    }

    /** The counts shown are the ones the run recorded, including its failures. */
    public function theHistoryReportsTheRunsOwnCounts(WebTester $I): void
    {
        $this->seedRun('2099-04-02', 'COMPLETED', 'manual', [
            'orders_received' => 200,
            'orders_inserted' => 12,
            'orders_updated' => 3,
            'orders_unchanged' => 185,
            'items_imported' => 640,
            'failures' => 0,
        ]);

        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->see('200');
        $I->see('185');
        $I->see('640');
    }

    // ------------------------------------------------------------------------------ fixtures

    private function signIn(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
    }

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
    }

    private function seedOrder(
        int $store,
        int $orderId,
        string $orderedOn,
        string $itemsStatus,
        string $total,
    ): void {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $orderId,
            'status' => 'completed',
            'type' => 'delivery',
            'total_amount' => $total,
            'payload_json' => '{}',
            'content_hash' => hash('sha256', (string) $orderId),
            // The PROVIDER's instant, which the order-date filter reads.
            'source_created_at' => (int) strtotime($orderedOn . ' 12:00:00 UTC'),
            'items_status' => $itemsStatus,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedItem(int $store, int $orderId, int $itemId, string $name, string $qty): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_order_items}}', [
            'store_account_id' => $store,
            'source_order_id' => $orderId,
            'source_item_id' => $itemId,
            'name' => $name,
            'qty' => $qty,
            'position' => $itemId - 1,
            'comparison_json' => '{"productName":"' . $name . '","additionals":[],"combinations":[]}',
            'content_hash' => hash('sha256', $name),
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /**
     * A history row, on a far-future day so it can never collide with a real run.
     *
     * @param array<string, mixed> $values
     */
    private function seedRun(string $day, string $status, string $trigger, array $values = []): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_case_order_sync_runs}}', [
            'business_day' => $day,
            'timezone' => 'America/New_York',
            'trigger_source' => $trigger,
            'status' => $status,
            'started_at' => $now,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ] + $values)->execute();

        $id = (int) $this->connection->getLastInsertID();

        /*
         * Fail loudly rather than orphan the row.
         *
         * Cleanup removes exactly the ids recorded here, so an id that never arrives leaves the
         * fixture in the table permanently — and a fixture run left CLAIMED holds the application's
         * global synchronisation claim, which silently disables the Sync button for every later test
         * AND for the browser until its lease expires. That happened; it cost a suite-wide failure
         * that looked nothing like its cause.
         */
        if ($id <= 0) {
            Assert::fail('Could not read the seeded run id, so this fixture would be orphaned.');
        }

        $this->createdRunIds[] = $id;

        return $id;
    }

    /** Only what this suite wrote: by run id, by store, by the fixture order ids. */
    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();
        $stores = [self::STORE_A, self::STORE_B];

        if ($this->createdRunIds !== []) {
            $connection->createCommand()
                ->delete('{{%order58_case_order_sync_runs}}', ['id' => $this->createdRunIds])
                ->execute();
            $this->createdRunIds = [];
        }

        // Items first, then orders: the foreign key cascades, but being explicit keeps the order
        // obvious to the next reader.
        $connection->createCommand()
            ->delete('{{%order58_order_items}}', ['store_account_id' => $stores])->execute();
        $connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => $stores])->execute();
        $connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['source_store_id' => $stores])->execute();
        IntegrationDb::cleanup($connection, '{{%order58_stores}}', ['source_id' => $stores]);
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::ADMIN]);
    }
}
