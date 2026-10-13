<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Order58\Items\ItemCanonicalizer;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function bin2hex;
use function gmdate;
use function hash;
use function json_encode;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function preg_replace;
use function random_bytes;
use function str_repeat;
use function trim;

/**
 * The original-vs-demo item comparison, rendered.
 *
 * ## What is really being guarded
 *
 * The defect, over HTTP. An original order whose line items have never been fetched used to make the
 * page report every line the trainee entered as "Extra in demo" — telling somebody who had recreated
 * the order perfectly that they had invented all of it. The page must say that the items are not
 * synchronized instead, and it must say it without accusing anybody.
 *
 * **No provider call.** The page reads two local tables; `ORDER58_ITEM_SYNC_ENABLED` is off and nothing
 * here presses the sync button.
 */
final class OrderTestingItemComparisonCest
{
    private const ADMIN = '__kf_item_compare_admin__';
    private const PASSWORD = 'ItemComparePassw0rd!secure';

    private const STORE_ID = 987655970;
    private const STORE_NAME = 'Item Comparison Fixture Store';
    private const HOST = 'kf-item-compare.order58.com';

    private const ORDER = 16800401;
    private const DEMO_ORDER = 16630821;
    private const PHONE = '19178532637';

    /** The client's own sample line, prefix and all. */
    private const WINGS = '[F7]Fried Chicken Wings (4)';

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

    // ------------------------------------------------------------------ the defect

    /**
     * The whole point of this phase.
     *
     * Items never fetched, demo order full of lines: the page must not call them extra.
     */
    public function anUnsynchronizedOriginalDoesNotCallEveryDemoLineExtra(WebTester $I): void
    {
        $this->seedOrder('PENDING');
        $this->seedDemoOrder([$this->wings(), $this->soup()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('have not been synchronized yet');
        $I->see('Not comparable', '.ot-items');
        $I->dontSee('Extra in demo', '.ot-items');
        // The trainee's work is still fully on the page.
        $I->see(self::WINGS);
        $I->see('Wonton Soup');
    }

    /** Queued is the same promise: not comparable yet, and nobody is blamed. */
    public function aQueuedOriginalIsReportedAsPendingNotAsADifference(WebTester $I): void
    {
        $this->seedOrder('QUEUED');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('queued for synchronization');
        $I->dontSee('Extra in demo', '.ot-items');
    }

    /** A failure with nothing ever stored says so, and says it is not the demo's fault. */
    public function aFailedSyncWithNoSnapshotSaysItIsNotADifference(WebTester $I): void
    {
        $this->seedOrder('FAILED');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('not a difference in the demo order');
        $I->dontSee('Extra in demo', '.ot-items');
    }

    // ------------------------------------------------------------------ a real comparison

    public function aSynchronizedOriginalIsComparedLineByLine(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Match', '.ot-items');
        $I->dontSee('Not comparable', '.ot-items');
        $I->dontSee('have not been synchronized');
    }

    /** The provider's exact name is what the page shows — the prefix is kitchen routing, not noise. */
    public function theProvidersExactNameIncludingItsPrefixIsDisplayed(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see(self::WINGS);
    }

    /** A wrong quantity is one difference on one line, not a missing line plus an extra one. */
    public function aWrongQuantityIsOneDifferenceOnTheRightLine(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);

        $wrong = $this->wings();
        $wrong['qty'] = '3';
        $this->seedDemoOrder([$wrong]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Different', '.ot-items');
        $I->dontSee('Missing in demo', '.ot-items');
        $I->dontSee('Extra in demo', '.ot-items');
    }

    public function anOmittedLineIsMissingAndAnAddedLineIsExtra(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->soup()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Missing in demo', '.ot-items');
        $I->see('Extra in demo', '.ot-items');
    }

    /** A verified empty original really does make a demo line extra. */
    public function anAuthoritativelyEmptyOriginalDoesReportExtraLines(WebTester $I): void
    {
        $this->seedOrder('EMPTY', syncedAt: '2026-10-09 10:00:00');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Extra in demo', '.ot-items');
        $I->dontSee('have not been synchronized');
    }

    /** The summary card names the state, so it is known before the table is read. */
    public function theSummaryCardNamesTheItemSyncState(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Original items', '.ot-summary');
        $I->see('Synced', '.ot-summary');
        $I->see('2026-10-09 10:00:00', '.ot-summary');
    }

    /** And it says "No items" rather than "0 items", which would read as a quiet failure. */
    public function anEmptyOriginalIsNamedAsSuchInTheSummary(WebTester $I): void
    {
        $this->seedOrder('EMPTY', syncedAt: '2026-10-09 10:00:00');
        $this->seedDemoOrder([]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('No items', '.ot-summary');
        $I->dontSee('0 items', '.ot-summary');
    }

    public function anUnsynchronizedOriginalIsNamedInTheSummaryToo(WebTester $I): void
    {
        $this->seedOrder('PENDING');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Not synced', '.ot-summary');
    }

    // ------------------------------------------------------------------ modifiers, by kind

    /**
     * Additions and combinations get their own rows.
     *
     * An operator fixes them differently — an addition is something asked for on top of the product, a
     * combination is a choice the product requires — so a single merged row would report a difference
     * without saying which kind.
     */
    public function additionsAndCombinationsAreShownAsSeparateRows(WebTester $I): void
    {
        $original = $this->wings();
        $original['data']['additionals'] = [
            ['additional_name' => 'Sauce', 'option_name' => 'Extra Hot', 'qty' => '2', 'price' => '0.50000'],
        ];
        $original['data']['combinations'] = [
            'options' => [['attribute_name' => 'Size', 'option_name' => 'Large']],
        ];

        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $original, 0);
        $this->seedDemoOrder([$original]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Additions', '.ot-items');
        $I->see('Combinations', '.ot-items');
        $I->see('Sauce: Extra Hot x2', '.ot-items');
        $I->see('Size: Large', '.ot-items');
    }

    /** A wrong addition is reported on the Additions row, and the combination row stays a match. */
    public function aWrongAdditionIsReportedOnItsOwnRow(WebTester $I): void
    {
        $original = $this->wings();
        $original['data']['additionals'] = [['additional_name' => 'Sauce', 'option_name' => 'Hot']];
        $original['data']['combinations'] = ['options' => [['attribute_name' => 'Size', 'option_name' => 'Large']]];

        $demo = $original;
        $demo['data']['additionals'] = [['additional_name' => 'Sauce', 'option_name' => 'Mild']];

        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $original, 0);
        $this->seedDemoOrder([$demo]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Different', '.ot-items');
        $I->see('Sauce: Hot', '.ot-items');
        $I->see('Sauce: Mild', '.ot-items');
        // The required choice was right, so its own row must not be dragged down with it.
        $I->see('Size: Large', '.ot-items');
    }

    /** Quantity, unit price and the product name each get a labelled row of their own. */
    public function productQuantityAndPriceEachHaveTheirOwnRow(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Product', '.ot-items');
        $I->see('Quantity', '.ot-items');
        $I->see('Unit price', '.ot-items');
        $I->see('11.35000', '.ot-items');
        // The un-prefixed name is what the Product row compares, so both forms appear on the page.
        $I->see('Fried Chicken Wings (4)', '.ot-items');
    }

    // ------------------------------------------------------------------ a kept snapshot

    public function aKeptSnapshotIsComparedAndLabelledStale(WebTester $I): void
    {
        $this->seedOrder('FAILED', syncedAt: '2026-10-08 09:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('most recent refresh failed');
        $I->see('2026-10-08 09:00:00');
        // Still compared: the kept snapshot is the only item data there is.
        $I->see('Match', '.ot-items');
    }

    public function aRefreshInFlightStillShowsTheLastGoodAnswer(WebTester $I): void
    {
        $this->seedOrder('PROCESSING', syncedAt: '2026-10-08 09:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('while a refresh is running');
        $I->see('Match', '.ot-items');
    }

    /** A line the provider removed is listed apart and never counted against the trainee. */
    public function aRemovedOriginalLineIsListedApart(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');

        $removed = $this->wings();
        $removed['deleted'] = '1';
        $this->seedItem(1, $removed, 0);
        $this->seedItem(2, $this->soup(), 1);
        $this->seedDemoOrder([$this->soup()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Removed from the original order');
        $I->seeElement('.ot-removed-items');
        $I->dontSee('Missing in demo', '.ot-items');
    }

    // ------------------------------------------------------------------ the two changes

    /** A field neither order filled in is not a row on either panel. */
    public function aFieldBlankOnBothOrdersIsHiddenOnBothPanels(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        foreach (['original', 'demo'] as $side) {
            $rows = $this->panelRows($I->grabPageSource(), $side);
            Assert::assertNotContains('line:Email', $rows, $side . ' still prints an empty Email row');
            Assert::assertNotContains('line:Payment method', $rows, $side . ' still prints an empty row');
        }
    }

    /** A field only one order filled in stays on both, so the comparison has somewhere to be. */
    public function aFieldOnOneSideOnlyStaysOnBothPanels(WebTester $I): void
    {
        $this->seedOrder(
            'SYNCED',
            syncedAt: '2026-10-09 10:00:00',
            reservation: ['phone' => self::PHONE, 'email' => 'marta@example.test'],
        );
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        foreach (['original', 'demo'] as $side) {
            $rows = $this->panelRows($I->grabPageSource(), $side);
            Assert::assertContains('line:Email', $rows, $side . ' dropped a field the other side has');
        }

        $I->see('marta@example.test', '.ot-book--original');
        $I->see('Missing in demo', '.ot-book--demo');
    }

    /** An address neither order has takes its whole section with it, heading included. */
    public function aDeliveryAddressEmptyOnBothSidesHidesTheWholeSection(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()], address: null, city: null);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->dontSee('Delivery address', '.ot-books');

        foreach (['original', 'demo'] as $side) {
            $rows = $this->panelRows($I->grabPageSource(), $side);
            Assert::assertNotContains('title:Delivery address', $rows);
            Assert::assertNotContains('line:City', $rows);
        }
    }

    /** And it is still there the moment either order has one. */
    public function aDeliveryAddressOnEitherSideKeepsTheSection(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Delivery address', '.ot-book--original');
        $I->see('Delivery address', '.ot-book--demo');
        $I->see('162 N Main St', '.ot-book--demo');
    }

    /**
     * Zero is a stated number, not an absence, and keeps its row.
     *
     * The fixture's demo order states no count at all, so this is also the one-sided case: the row
     * survives on both panels and the demo side carries the verdict.
     */
    public function aZeroIsAStatedNumberAndNotAnAbsence(WebTester $I): void
    {
        $this->seedOrder(
            'SYNCED',
            syncedAt: '2026-10-09 10:00:00',
            reservation: ['phone' => self::PHONE, 'customer_count' => 0],
        );
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        foreach (['original', 'demo'] as $side) {
            $rows = $this->panelRows($I->grabPageSource(), $side);
            Assert::assertContains('line:Customer count', $rows, $side . ' dropped a stated zero');
        }

        $I->see('0', '.ot-book--original');
    }

    /** Whatever is hidden is hidden from both panels, which is what keeps the two in step. */
    public function whateverIsHiddenIsHiddenFromBothPanels(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedItem(2, $this->soup(), 1);
        $this->seedDemoOrder([$this->wings()], address: null, city: null);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $html = $I->grabPageSource();
        Assert::assertSame(
            $this->panelRows($html, 'original'),
            $this->panelRows($html, 'demo'),
            'the two panels no longer agree about which rows exist',
        );
    }

    /** The totals keep their own section, their labels and their verdicts — only their position moved. */
    public function theTotalsKeepTheirSectionAndTheirVerdicts(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->seeElement('.ot-book--original .ot-book__section--totals');
        $I->seeElement('.ot-book--demo .ot-book__section--totals');
        $I->see('Totals', '.ot-book--original');
        $I->see('Total', '.ot-book--demo');
        $I->seeElement('.ot-book--original .ot-book__line--grand');
        $I->seeElement('.ot-book--demo .ot-book__line--grand');
    }

    // ------------------------------------------------------------------ the rest of the page

    /** The non-item half of the comparison is untouched by any of this. */
    public function theOrderAndCustomerSectionsStillCompare(WebTester $I): void
    {
        $this->seedOrder('PENDING');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Customer');
        $I->see('Delivery address');
        $I->see('Totals');
        $I->see('Order items');
    }

    /** And still no score, which is a separate, separately specified piece of work. */
    public function thePageStillReportsNoAccuracyNumber(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('These are differences, not a score');
        $I->dontSee('%');
    }

    public function aGuestSeesNoneOfIt(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedDemoOrder([$this->wings()]);

        $I->amOnPage($this->url());

        $I->seeCurrentUrlEquals('/login');
        $I->dontSee(self::WINGS);
    }

    // ------------------------------------------------------------------ harness

    // ---------------------------------------------------------------- invoice layout

    /**
     * The page is one comparison, not two unrelated columns.
     *
     * Asserted on the panel headings rather than on a screenshot: what matters is that each order
     * number sits inside its own panel, because the whole redesign rests on a reader being able to
     * tell at a glance which column is which.
     */
    public function theTwoOrdersAreHeadedAsTheirOwnPanels(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Order Comparison', 'h1');
        $I->dontSee('vs demo #', 'h1');

        $I->see('Original order', '.ot-book--original');
        $I->see((string) self::ORDER, '.ot-book--original');
        $I->see('Demo / test order', '.ot-book--demo');
        $I->see((string) self::DEMO_ORDER, '.ot-book--demo');
    }

    /** The original's name is read from the payload, and belongs to the original panel alone. */
    public function theOriginalCustomerNameComesFromTheOrderPayload(WebTester $I): void
    {
        $this->seedOrder(
            'SYNCED',
            syncedAt: '2026-10-09 10:00:00',
            reservation: ['first_name' => 'Marta', 'last_name' => 'Nowak', 'phone' => self::PHONE],
        );
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Marta Nowak', '.ot-book--original');
        // The demo document this fixture writes carries no name, so the original's must not leak
        // across. Two panels showing one customer would make every comparison look like a match.
        $I->dontSee('Marta Nowak', '.ot-book--demo');
    }

    /**
     * An order naming nobody says so, and says it the same way everywhere.
     *
     * This is the common case: the default fixture reservation holds a phone and no name, exactly as
     * the mirror does for all but six of its orders.
     */
    public function anOrderWithoutANameShowsADashRatherThanABlank(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('-', '.ot-book--original .ot-book__who');
    }

    /** The shipping block answers when the reservation names nobody — the one real fallback in use. */
    public function theNameFallsBackToShippingWhenTheReservationIsEmpty(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00', reservation: ['phone' => self::PHONE]);
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);
        $this->connection->createCommand()->update(
            '{{%order58_orders}}',
            ['payload_json' => (string) json_encode([
                'id' => (string) self::ORDER,
                'status' => 'completed',
                'type' => 'delivery',
                'data' => (string) json_encode([
                    'reservation' => ['first_name' => '', 'last_name' => null, 'phone' => self::PHONE],
                    'shipping' => ['first_name' => 'Rafal', 'last_name' => 'Zieba'],
                ]),
            ])],
            ['account_id' => self::STORE_ID, 'source_order_id' => self::ORDER],
        )->execute();

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Rafal Zieba', '.ot-book--original');
    }

    /** Both existing controls survive the redesign; losing either would strand the page. */
    public function theExistingControlsAreStillThere(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->seeElement('form[action*="order-items/sync"] button[type="submit"]');
        $I->see('Sync Original Order Items');
        $I->see('All demo orders for this call');
    }

    /**
     * The wider content column is opt-in, and stays opt-in.
     *
     * Two invoices need more room than the reading column every other admin page wants, so the layout
     * widens only for a page that asks. The second half is the half worth guarding: if the parameter
     * were ever read as "default on", every list and form in the admin would silently re-flow, and
     * nothing on this page would look wrong enough to notice.
     */
    public function onlyThisPageAsksForTheWiderColumn(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);

        $I->amOnPage($this->url());
        $I->seeElement('.content.content--wide');
        // Both panels live in the one grid, so they are columns of a page and not two stacked cards.
        $I->seeElement('.ot-books > .ot-book--original');
        $I->seeElement('.ot-books > .ot-book--demo');

        $I->amOnPage('/order-testing');
        $I->seeElement('.content');
        $I->dontSeeElement('.content--wide');
    }

    // ------------------------------------------------- the rows the shared grid pairs up

    /**
     * The invariant the side-by-side alignment rests on.
     *
     * Both panels are laid onto one set of row tracks, so row n of the left is row n of the right and
     * the track is as tall as the taller of the two. That only holds while both panels put out the same
     * rows in the same order — a side that quietly skipped one would shift everything below it against
     * its opposite number, and the Totals would end up comparing the wrong lines.
     *
     * Nothing in a browser enforces it, so it is asserted here, on a comparison built to be lopsided:
     * a line only the original has, a line only the demo has, a line the provider removed, and options
     * long enough to wrap on one side and not the other.
     */
    public function theTwoPanelsPutOutTheSameRowsInTheSameOrder(WebTester $I): void
    {
        $this->seedLopsidedComparison();

        $this->signIn($I);
        $I->amOnPage($this->url());

        $html = $I->grabPageSource();
        $original = $this->panelRows($html, 'original');
        $demo = $this->panelRows($html, 'demo');

        Assert::assertNotEmpty($original, 'the original panel rendered no rows at all');
        Assert::assertSame($original, $demo, 'the two invoices disagree about which row is which');
    }

    /**
     * The shared grid declares a fixed number of row tracks, because the real count cannot reach it:
     * it would have to travel from PHP in an inline style, and `style-src 'self'` forbids that.
     *
     * The count is an upper bound, so the only way it can fail is an order long enough to exceed it.
     * This is where that would be caught — a dozen lines is already well beyond any real order, and
     * lands nowhere near the bound.
     */
    public function aLongOrderStaysWellInsideTheSharedGridsTracks(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');

        $demoLines = [];

        for ($i = 0; $i < 12; $i++) {
            $line = $this->wings();
            $line['id'] = 45309000 + $i;
            $line['name'] = '[F' . $i . ']Fried Chicken Wings (4)';
            $this->seedItem($i + 1, $line, $i);
            $demoLines[] = $line;
        }

        $this->seedDemoOrder($demoLines);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $rows = $this->panelRows($I->grabPageSource(), 'original');

        // Rows blank on both sides are no longer rendered, so the same order is shorter than it was
        // (73 rows, not ~120). The bound this test exists for is the upper one.
        Assert::assertGreaterThan(50, count($rows), 'twelve lines should still make a long document');
        // `grid-template-rows: repeat(1000, auto)` in demo-orders.css.
        Assert::assertLessThan(1000, count($rows), 'this order would overflow the shared grid');
    }

    /**
     * A line the provider removed is answered on the demo side rather than left blank.
     *
     * The original lists them; the demo says why it has none. Saying nothing would cost the demo panel
     * a row and put the two invoices out of step from there down.
     */
    public function removedLinesAreAnsweredOnTheDemoSide(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $removed = $this->wings();
        $removed['deleted'] = '1';
        $this->seedItem(1, $removed, 0);
        $this->seedDemoOrder([$this->soup()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Removed from the original order', '.ot-book--original');
        $I->seeElement('.ot-book--original .ot-removed-items');

        $I->see('Removed lines', '.ot-book--demo');
        $I->see('not expected here and nothing is missing', '.ot-book--demo');
        // The list itself belongs to the original: the demo never had those lines to show.
        $I->dontSeeElement('.ot-book--demo .ot-removed-items');
    }

    /** Several items, each pairing with its opposite number and keeping its own verdict. */
    public function severalItemsEachKeepTheirOwnPlaceAndVerdict(WebTester $I): void
    {
        $this->seedLopsidedComparison();

        $this->signIn($I);
        $I->amOnPage($this->url());

        // Present on both: compared. Present on one: named as such, on the side that has it.
        $I->see('Fried Chicken Wings (4)', '.ot-book--original');
        $I->see('Fried Chicken Wings (4)', '.ot-book--demo');
        $I->see('Wonton Soup', '.ot-book--original');
        $I->dontSee('Wonton Soup', '.ot-book--demo');
        $I->see('Crab Rangoon', '.ot-book--demo');
        $I->dontSee('Crab Rangoon', '.ot-book--original');

        $I->see('Not on this order', '.ot-book--original');
        $I->see('Not on this order', '.ot-book--demo');

        $I->see('Missing in demo', '.ot-book--demo');
        $I->see('Extra in demo', '.ot-book--demo');
        // Verdicts stay on the side that is being judged, however many items there are.
        $I->dontSee('Missing in demo', '.ot-book--original');
        $I->dontSee('Extra in demo', '.ot-book--original');
    }

    /**
     * One comparison with every shape that makes the two sides disagree about a row's height.
     *
     * Wings are on both sides with options long enough to wrap on the original only; the soup is the
     * original's alone; the rangoon is the demo's alone; the egg roll was removed by the provider.
     */
    private function seedLopsidedComparison(): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');

        $wings = $this->wings();
        $wings['data']['additionals'] = [
            ['additional_name' => 'Add / Extra', 'option_name' => 'Extra crispy, well done, sauce on the side', 'qty' => '1', 'price' => '0.50000'],
        ];
        $this->seedItem(1, $wings, 0);
        $this->seedItem(2, $this->soup(), 1);

        $removed = $this->wings();
        $removed['id'] = 45309901;
        $removed['name'] = '[A1]Egg Roll';
        $removed['deleted'] = '1';
        $this->seedItem(3, $removed, 2);

        // A different product, not a renamed one: sharing the soup's id would have the comparator pair
        // the two and leave neither side a line short, which is the case this fixture is here to make.
        $rangoon = $this->soup();
        $rangoon['id'] = 45309902;
        $rangoon['name'] = '[A9]Crab Rangoon (6)';
        $rangoon['price'] = '6.50000';
        $rangoon['product_id'] = 506999;
        $rangoon['data']['product'] = ['id' => 506999, 'sn' => 'A9', 'name' => 'Crab Rangoon (6)'];

        $this->seedDemoOrder([$this->wings(), $rangoon]);
    }

    /**
     * The rows of one panel, in the order they are drawn, each reduced to what both sides must agree on.
     *
     * Values are left out on purpose — they differ, and that is the point of the page. What must match
     * is the shape: the same kinds of row, carrying the same field labels, in the same order.
     *
     * The selector names every element that occupies a row of the shared grid. It is explicit rather
     * than "every descendant", because a wrapper that still had a box would not show up here, and that
     * is exactly the mistake this test exists to catch.
     *
     * @return list<string>
     */
    private function panelRows(string $html, string $side): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $has = static fn(string $class): string
            => "contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')";

        $panel = $xpath->query('//section[' . $has('ot-book--' . $side) . ']')->item(0);

        if (!$panel instanceof DOMElement) {
            return [];
        }

        $rows = $xpath->query(
            './/*[' . $has('ot-book__head')
            . ' or ' . $has('ot-book__section-title')
            . ' or ' . $has('ot-book__line')
            . ' or ' . $has('ot-book__item-head')
            . ' or ' . $has('ot-book__removed')
            . ' or (self::p and parent::*[' . $has('ot-book__section--items') . '])]',
            $panel,
        );

        $out = [];

        foreach ($rows as $row) {
            if (!$row instanceof DOMElement) {
                continue;
            }

            $class = $row->getAttribute('class');

            if (str_contains($class, 'ot-book__line')) {
                $term = $xpath->query('.//*[' . $has('ot-book__term') . ']', $row)->item(0);
                $out[] = 'line:' . self::flatten($term?->textContent ?? '');

                continue;
            }

            if (str_contains($class, 'ot-book__section-title')) {
                $out[] = 'title:' . self::flatten($row->textContent);

                continue;
            }

            // An item's heading names what was ordered, and the two sides name different things — or
            // one of them names nothing at all. Only its position in the sequence has to match.
            $out[] = match (true) {
                str_contains($class, 'ot-book__head') => 'head',
                str_contains($class, 'ot-book__item-head') => 'item',
                str_contains($class, 'ot-book__removed') => 'removed',
                default => 'note',
            };
        }

        return $out;
    }

    private static function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function url(): string
    {
        return '/order-testing/store/' . self::STORE_ID . '/order/' . self::ORDER
            . '/demo/' . self::DEMO_ORDER;
    }

    private function signIn(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
    }

    /** @return array<string, mixed> */
    private function wings(): array
    {
        return [
            'id' => 45308981,
            'qty' => '1',
            'name' => self::WINGS,
            'price' => '11.35000',
            'desc' => null,
            'deleted' => '0',
            'order_id' => self::ORDER,
            'product_id' => 506941,
            'data' => [
                'product' => ['id' => 506941, 'sn' => 'F7', 'name' => 'Fried Chicken Wings (4)'],
                'additionals' => null,
                'combinations' => null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function soup(): array
    {
        return [
            'id' => 45308991,
            'qty' => '1',
            'name' => 'Wonton Soup',
            'price' => '2.00000',
            'deleted' => '0',
            'order_id' => self::ORDER,
            'product_id' => 538451,
            'data' => [
                'product' => ['id' => 538451, 'sn' => 'C12', 'name' => 'Wonton Soup'],
                'additionals' => null,
                'combinations' => null,
            ],
        ];
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

        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => self::STORE_NAME,
            'slug' => 'kf-item-compare-' . self::STORE_ID,
            'source_system' => 'order58',
            'source_store_id' => self::STORE_ID,
            'source_name' => self::STORE_NAME,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /**
     * @param array<string, mixed>|null $reservation what the order's reservation block holds. Defaults
     *                                               to the real shape: a phone and no name at all,
     *                                               which is what 390 of 396 mirrored orders carry.
     */
    private function seedOrder(string $itemsStatus, ?string $syncedAt = null, ?array $reservation = null): void
    {
        $now = gmdate('Y-m-d H:i:s');
        // Deliberately WITHOUT an `items` key: this is what the orders mirror actually holds, and the
        // reason the items come from their own table.
        $payload = (string) json_encode([
            'id' => (string) self::ORDER,
            'status' => 'completed',
            'type' => 'delivery',
            'total_amount' => '13.35000',
            'data' => (string) json_encode(['reservation' => $reservation ?? ['phone' => self::PHONE]]),
        ]);

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => self::STORE_ID,
            'source_order_id' => self::ORDER,
            'reservation_phone' => self::PHONE,
            'type' => 'delivery',
            'payment_method' => 'cash',
            'status' => 'completed',
            'total_amount' => '13.35000',
            'payload_json' => $payload,
            'content_hash' => hash('sha256', $payload),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'items_status' => $itemsStatus,
            'items_synced_at' => $syncedAt,
        ])->execute();
    }

    /** @param array<string, mixed> $raw */
    private function seedItem(int $sourceItemId, array $raw, int $position): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $canonical = (new ItemCanonicalizer())->canonicalize($raw);

        $this->connection->createCommand()->insert('{{%order58_order_items}}', [
            'store_account_id' => self::STORE_ID,
            'source_order_id' => self::ORDER,
            'source_item_id' => $sourceItemId,
            'name' => (string) ($raw['name'] ?? ''),
            'qty' => (string) ($raw['qty'] ?? '0'),
            'price' => $raw['price'] ?? null,
            'product_id' => $raw['product_id'] ?? null,
            'deleted' => (int) ($raw['deleted'] ?? 0),
            'position' => $position,
            'comparison_json' => (string) json_encode($canonical->toArray()),
            'content_hash' => hash('sha256', (string) json_encode($raw)),
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** @param list<array<string, mixed>> $items */
    private function seedDemoOrder(
        array $items,
        ?string $address = '162 N Main St',
        ?string $city = 'Freeport',
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $payload = (string) json_encode([
            'id' => self::DEMO_ORDER,
            'status' => 'completed',
            'type' => 'delivery',
            'total_amount' => '13.35000',
            'items' => $items,
            'data' => ['reservation' => ['phone' => self::PHONE, 'first_name' => 'testa']],
        ]);

        $this->connection->createCommand()->insert('{{%order_testing_demo_orders}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => self::STORE_ID,
            'source_order_id' => self::ORDER,
            'demo_order_id' => self::DEMO_ORDER,
            'customer_phone' => self::PHONE,
            'customer_first_name' => 'testa',
            'order_type' => 'delivery',
            'payment_method' => 'cash',
            'status' => 'completed',
            'total_amount' => '13.35000',
            'address' => $address,
            'city' => $city,
            'source_filename' => self::ORDER . '-' . self::DEMO_ORDER . '.json',
            'content_hash' => hash('sha256', $payload),
            'raw_payload' => $payload,
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

        $this->connection->createCommand()
            ->delete('{{%order_testing_demo_orders}}', ['store_source_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order_testing_attempts}}', ['store_source_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_order_items}}', ['store_account_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => self::STORE_ID])->execute();

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
