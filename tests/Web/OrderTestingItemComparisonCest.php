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

use function array_map;
use function array_replace;
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
        $I->see('Not available', '.ot-items');
        $I->dontSee('Missing in original', '.ot-items');
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
        $I->dontSee('Missing in original', '.ot-items');
    }

    /** A failure with nothing ever stored says so, and says it is not the demo's fault. */
    public function aFailedSyncWithNoSnapshotSaysItIsNotADifference(WebTester $I): void
    {
        $this->seedOrder('FAILED');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('not a difference in the demo order');
        $I->dontSee('Missing in original', '.ot-items');
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
        $I->dontSee('Not available', '.ot-items');
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
        $I->dontSee('Missing in original', '.ot-items');
    }

    public function anOmittedLineIsMissingAndAnAddedLineIsExtra(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->soup()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Missing in demo', '.ot-items');
        $I->see('Missing in original', '.ot-items');
    }

    /** A verified empty original really does make a demo line extra. */
    public function anAuthoritativelyEmptyOriginalDoesReportExtraLines(WebTester $I): void
    {
        $this->seedOrder('EMPTY', syncedAt: '2026-10-09 10:00:00');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Missing in original', '.ot-items');
        $I->dontSee('have not been synchronized');
    }

    /**
     * The summary card is about the comparison, not the plumbing behind it.
     *
     * The import file name and the item-synchronisation timestamp were technical detail the client
     * asked to remove (2026-10-30). When the original's items are NOT usable the page still says so,
     * in the alert above the invoices — see the two tests below.
     */
    public function theSummaryCardLeavesOutSyncDetails(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Source order', '.ot-summary');
        $I->dontSee('Original items', '.ot-summary');
        $I->dontSee('2026-10-09 10:00:00', '.ot-summary');
        $I->dontSee('Imported from', '.ot-summary');
        $I->dontSee('.json');
    }

    /** An empty original says so where its items would be. */
    public function anEmptyOriginalIsNamedAsSuchOnTheInvoice(WebTester $I): void
    {
        $this->seedOrder('EMPTY', syncedAt: '2026-10-09 10:00:00');
        $this->seedDemoOrder([]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('No line items', '.ot-book--original');
    }

    public function anUnsynchronizedOriginalIsStillAnnouncedAboveTheInvoices(WebTester $I): void
    {
        $this->seedOrder('PENDING');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('have not been synchronized yet', '.alert');
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
     * The fixture's demo order states no tip at all, so this is also the one-sided case: the row
     * survives on both panels.
     */
    public function aZeroIsAStatedNumberAndNotAnAbsence(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00', extra: ['tip' => '0.00000']);
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        foreach (['original', 'demo'] as $side) {
            $rows = $this->panelRows($I->grabPageSource(), $side);
            Assert::assertContains('line:Tip', $rows, $side . ' dropped a stated zero');
        }

        $I->see('0.00000', '.ot-book--original');
    }

    /**
     * Customer count, order status and the product code are not shown, on either panel.
     *
     * All three are stated here, on both orders — so their absence is the page's decision, not an
     * empty field. They are not scored either; the unit tests hold that.
     */
    public function theCountTheStatusAndTheProductCodeAreNotShown(WebTester $I): void
    {
        $this->seedOrder(
            'SYNCED',
            syncedAt: '2026-10-09 10:00:00',
            reservation: ['phone' => self::PHONE, 'customer_count' => 2],
        );
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        foreach (['original', 'demo'] as $side) {
            $rows = $this->panelRows($I->grabPageSource(), $side);
            Assert::assertNotContains('line:Customer count', $rows, $side);
            Assert::assertNotContains('line:Status', $rows, $side);
            Assert::assertNotContains('line:Product SN', $rows, $side);
            Assert::assertContains('line:Order type', $rows, $side);
            Assert::assertContains('line:Phone', $rows, $side);
        }

        // Nor in the line's heading, where the code used to follow the name. (This fixture's name keeps
        // the provider's own `[F7]` prefix, which is the name as sent and stays.)
        $I->dontSeeElement('.ot-book__item-name code');
    }

    /**
     * Each option is a line of its own, exactly as the order named it.
     *
     * One addition carries a comma inside its own name. It must survive whole — the list is read from
     * the line's own modifiers, never split out of a sentence.
     */
    public function modifiersAreListedOnePerLine(WebTester $I): void
    {
        $original = $this->wings();
        $original['data']['additionals'] = [
            ['additional_name' => 'No / Not / Exchange for items', 'option_name' => 'Not fried too hard'],
            ['additional_name' => 'Sauce additional', 'option_name' => 'Garlic, extra hot'],
        ];
        $original['data']['combinations'] = [
            'options' => [['attribute_name' => 'Combination With', 'option_name' => 'Shrimp FR']],
        ];

        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $original, 0);
        $this->seedDemoOrder([$original]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $entries = $I->grabMultiple('.ot-book--original .ot-list__item');
        Assert::assertSame(
            [
                // Options: every modifier.
                'No / Not / Exchange for items: Not fried too hard',
                'Sauce additional: Garlic, extra hot',
                'Combination With: Shrimp FR',
                // Additions.
                'No / Not / Exchange for items: Not fried too hard',
                'Sauce additional: Garlic, extra hot',
                // Combinations.
                'Combination With: Shrimp FR',
            ],
            array_map(static fn(string $text): string => self::flatten($text), $entries),
        );
        Assert::assertSame($entries, $I->grabMultiple('.ot-book--demo .ot-list__item'));
    }

    /**
     * A different unit price is shown and pointed out, and costs nothing.
     *
     * Everything the score counts agreed, so the line, its section and the order all read 100% — and
     * the price row says "Not scored", not "Different".
     */
    public function aDifferentUnitPriceIsPointedOutButNotScored(WebTester $I): void
    {
        $demo = $this->wings();
        $demo['price'] = '12.05000';

        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$demo]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('11.35000', '.ot-book--original .ot-items');
        $I->see('12.05000', '.ot-book--demo .ot-items');
        $I->see('Not scored', '.ot-book--demo .ot-items');
        $I->dontSee('Different', '.ot-book--demo .ot-items');
        $I->dontSeeElement('.ot-book--demo .ot-items .ot-book__line--different');
        $I->see('100%', '.ot-book--demo .ot-sscore__value');
        $I->see('100%', '.ot-score__value');
    }

    /** Totals are for reference: no score badge, no verdicts and no tint, however the amounts differ. */
    public function theTotalsCarryNoScoreAndNoVerdicts(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00', extra: ['tax' => '1.00000']);
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()], extra: ['total_amount' => '99.00000']);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('99.00000', '.ot-book--demo .ot-book__section--totals');
        $I->see('13.35000', '.ot-book--original .ot-book__section--totals');
        $I->dontSeeElement('.ot-book__section--totals .ot-sscore');
        $I->dontSeeElement('.ot-book__section--totals .ot-mark');
        $I->dontSeeElement('.ot-book__section--totals [class*="ot-book__line--different"]');
        $I->dontSeeElement('.ot-book__section--totals [class*="ot-book__line--missing"]');
        // Neither difference moves the overall: everything scored agreed.
        $I->see('100%', '.ot-score__value');
    }

    /**
     * Order details and payment details share one score badge.
     *
     * Order type, payment method and card agree; the expiry does not: 3 of 4 — 75%, beside Order
     * details, with no badge of its own under Payment details.
     */
    public function orderAndPaymentDetailsShareOneScore(WebTester $I): void
    {
        $card = ['payment_method' => 'credit card', 'card_last4' => '4242', 'card_expiry' => '12/2099'];

        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00', extra: $card);
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()], extra: ['card_expiry' => '11/2099'] + $card);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('75%', '//section[contains(@class, "ot-book--demo")]//h3[contains(., "Order details")]');
        $I->see('4 of 4 fields', '//section[contains(@class, "ot-book--demo")]//h3[contains(., "Order details")]');
        $I->dontSeeElement('.ot-book__subhead .ot-sscore');
        $I->see('•••• 4242', '.ot-book--demo');
        $I->see('Different', '.ot-book--demo .ot-book__line--different');
        $I->see('11/2099', '.ot-book--demo .ot-book__line--different');
    }

    /**
     * The delivery address has its own score now.
     *
     * The original states a city and a postcode; the demo states the same city and no postcode. City
     * agrees, the postcode is missing — 1 of 2, 50%. The street line cannot be compared and is shown,
     * not counted.
     */
    public function theDeliveryAddressIsScored(WebTester $I): void
    {
        $this->seedOrder(
            'SYNCED',
            syncedAt: '2026-10-09 10:00:00',
            shipping: ['city' => 'Freeport', 'zipcode' => '11520'],
        );
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $heading = '//section[contains(@class, "ot-book--demo")]//h3[contains(., "Delivery address")]';
        $I->see('50%', $heading);
        $I->see('2 of 3 fields', $heading);
        $I->see('Missing in demo', '.ot-book--demo');
        $I->see('Not available', '.ot-book--demo');
    }

    /**
     * The receipt follows the same rules: no Customer count or Status row, a Summary with no verdicts,
     * and a line that differs only in its unit price is not called different.
     */
    public function theReceiptHidesTheCountAndStatusAndJudgesNeitherPriceNorSummary(WebTester $I): void
    {
        $demo = $this->wings();
        $demo['price'] = '12.05000';

        $this->seedOrder(
            'SYNCED',
            syncedAt: '2026-10-09 10:00:00',
            reservation: ['phone' => self::PHONE, 'customer_count' => 2],
            extra: ['tax' => '1.00000'],
        );
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$demo], extra: ['total_amount' => '99.00000']);

        $this->signIn($I);
        $I->amOnPage($this->receiptUrl());

        $I->dontSee('Customer count', '.rc-pair');
        $I->dontSee('Status', '.rc-pair');
        $I->see('Order type', '.rc--demo');
        // Both prices still printed; the line is not called different for them.
        $I->see('11.35', '.rc--original');
        $I->see('12.05', '.rc--demo');
        $I->dontSee('Different', '.rc--demo .rc__line--item');
        // The Summary prints its amounts and nothing else.
        $I->see('99.00', '.rc--demo');
        $I->dontSeeElement('.rc__sum .rc-mark');
        $I->dontSeeElement('.rc__sum[data-rc-status]');
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

    // ------------------------------------------------------------------ the receipt

    /** The one change to the comparison page: a button, pointing at the receipt for this demo order. */
    public function theComparisonPageOffersAReceiptForThisDemoOrder(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        // The controls have their own row below the heading now — see `.ot-toolbar` in the template.
        $I->see('View Receipt', '.ot-toolbar__actions');
        $I->seeElement('.ot-toolbar__actions a[href="' . $this->receiptUrl() . '"]');
    }

    /** Two receipts, each showing its own order's number and lines. */
    public function theReceiptPageShowsBothOrdersAsSeparateReceipts(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->receiptUrl());

        $I->seeResponseCodeIs(200);
        $I->seeElement('.rc--original');
        $I->seeElement('.rc--demo');
        $I->see('Original order receipt', '.rc--original');
        $I->see('Demo order receipt', '.rc--demo');
        $I->see('#' . self::ORDER, '.rc--original .rc__number');
        $I->see('#' . self::DEMO_ORDER, '.rc--demo .rc__number');
        $I->see('Fried Chicken Wings (4)', '.rc--original');
        $I->see('Fried Chicken Wings (4)', '.rc--demo');
    }

    /** The sections are headed as a receipt's, not as the comparison's field groups. */
    public function theReceiptSectionsCarryReceiptHeadings(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->receiptUrl());

        foreach (['original', 'demo'] as $side) {
            $I->see('Customer Details', '.rc--' . $side);
            $I->see('Order Details', '.rc--' . $side);
            $I->see('Order Items', '.rc--' . $side);
            $I->see('Summary', '.rc--' . $side);
        }
    }

    /** Amounts are written for a person: two decimals, never the stored five. */
    public function amountsAreWrittenToTwoDecimals(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->receiptUrl());

        $I->see('13.35', '.rc--demo');
        $I->dontSee('13.35000', '.rc--demo');
        $I->dontSee('11.35000', '.rc--original');
    }

    /**
     * The verdicts belong to the demo receipt, because they judge what the trainee entered.
     *
     * The original card carries none: a receipt of what was actually ordered is not a verdict on
     * anything, and marking it would invite reading the left-hand column as the one under test.
     */
    public function theVerdictsAreOnTheDemoReceiptOnly(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedItem(2, $this->soup(), 1);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->receiptUrl());

        $I->seeElement('.rc--demo .rc-mark');
        $I->dontSeeElement('.rc--original .rc-mark');
        $I->see('Missing in demo', '.rc--demo');
    }

    /** The summary strip counts the very rows the receipts draw. */
    public function theSummaryCountsTheRowsTheReceiptsDraw(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedItem(2, $this->soup(), 1);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->receiptUrl());

        $I->seeElement('[data-rc-summary]');
        $I->seeElement('[data-rc-filter="MISSING_IN_DEMO"]');

        // Every chip's count equals the rows carrying that verdict on one card — the two cards mirror
        // each other, so one side is the whole list.
        $html = $I->grabPageSource();

        foreach ($this->chipCounts($html) as $status => $claimed) {
            Assert::assertSame(
                $this->rowsWithStatus($html, $status),
                $claimed,
                'the summary claims ' . $claimed . ' ' . $status . ' rows',
            );
        }
    }

    /** A field neither order filled in is not a row, and so is not counted as agreement. */
    public function aFieldEmptyOnBothOrdersIsNotCountedAsAMatch(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->receiptUrl());

        // Neither order carries an email, so neither receipt prints the row.
        $I->dontSee('Email', '.rc--original');
        $I->dontSee('Email', '.rc--demo');
    }

    /** Both receipts put out the same rows in the same order, which is what lets them be read across. */
    public function bothReceiptsPutOutTheSameRowsInTheSameOrder(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedItem(2, $this->soup(), 1);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->receiptUrl());

        $html = $I->grabPageSource();
        Assert::assertSame(
            $this->receiptRows($html, 'original'),
            $this->receiptRows($html, 'demo'),
            'the two receipts no longer agree about which rows exist',
        );
    }

    /** A long order stays inside the shared grid's tracks. */
    public function theReceiptPairStaysInsideItsSharedTracks(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');

        $lines = [];

        for ($i = 0; $i < 12; $i++) {
            $line = $this->wings();
            $line['id'] = 45409000 + $i;
            $line['name'] = '[F' . $i . ']Fried Chicken Wings (4)';
            $this->seedItem($i + 1, $line, $i);
            $lines[] = $line;
        }

        $this->seedDemoOrder($lines);

        $this->signIn($I);
        $I->amOnPage($this->receiptUrl());

        // `grid-template-rows: repeat(400, auto)` in receipt.css.
        Assert::assertLessThan(
            400,
            count($this->receiptRows($I->grabPageSource(), 'original')),
            'this order would overflow the shared grid',
        );
    }

    /**
     * The original order is named, and named as the original's.
     *
     * Its number and its customer appear on their own labelled line. Nothing else of the original's is
     * on this page, which is what keeps the two orders' figures from being read as one.
     */
    public function theReceiptNamesTheOriginalOrderAndItsCustomerSeparately(WebTester $I): void
    {
        $this->seedOrder(
            'SYNCED',
            syncedAt: '2026-10-09 10:00:00',
            reservation: ['phone' => self::PHONE, 'first_name' => 'Marta', 'last_name' => 'Nowak'],
        );
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->receiptUrl());

        // The original's name is on the ORIGINAL receipt, in its own Customer Details rows...
        $I->see('Customer Details', '.rc--original');
        $I->see('Marta', '.rc--original');
        $I->see('Nowak', '.rc--original');
        $I->dontSee('Nowak', '.rc--demo');
        // ...and the demo's own customer stays on the demo receipt.
        $I->see('testa', '.rc--demo');
        $I->dontSee('testa', '.rc--original');
    }

    /** With no name anywhere in the original's JSON, the receipt says so with a dash. */
    public function theReceiptPrintsADashWhenTheOriginalHasNoName(WebTester $I): void
    {
        // The default reservation carries a phone and no name at all, which is the common real shape.
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->receiptUrl());

        // No name anywhere in the original's JSON, so its receipt prints a dash — and does not borrow
        // the trainee's name to fill the gap.
        $I->see('-', '.rc--original');
        $I->dontSee('testa', '.rc--original');
    }

    /** The name falls through to the shipping block, exactly as the comparison does. */
    public function theReceiptFallsBackToTheShippingNameForTheOriginal(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00', reservation: ['phone' => self::PHONE]);
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        // Written straight into the payload, as the comparison's own shipping-fallback test does: the
        // seed helper models the common shape, and this is deliberately the uncommon one.
        $this->connection->createCommand()->update(
            '{{%order58_orders}}',
            ['payload_json' => (string) json_encode([
                'id' => (string) self::ORDER,
                'status' => 'completed',
                'type' => 'delivery',
                'total_amount' => '13.35000',
                'data' => (string) json_encode([
                    'reservation' => ['first_name' => '  ', 'last_name' => null, 'phone' => self::PHONE],
                    'shipping' => ['first_name' => 'Rafal', 'last_name' => 'Zieba'],
                ]),
            ])],
            ['account_id' => self::STORE_ID, 'source_order_id' => self::ORDER],
        )->execute();

        $this->signIn($I);
        $I->amOnPage($this->receiptUrl());

        $I->see('Rafal', '.rc--original');
        $I->see('Zieba', '.rc--original');
        $I->dontSee('Zieba', '.rc--demo');
    }

    /**
     * The three ids in the path are the ownership check.
     *
     * A demo order that exists, asked for under another store, is not found — the same answer as an id
     * that never existed, and produced by the lookup rather than by a test somebody has to remember.
     */
    public function aReceiptForAnotherStoresDemoOrderIsNotFound(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);

        $I->amOnPage('/order-testing/store/' . (self::STORE_ID + 1) . '/order/' . self::ORDER
            . '/demo/' . self::DEMO_ORDER . '/receipt');
        $I->seeResponseCodeIs(404);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . (self::ORDER + 1)
            . '/demo/' . self::DEMO_ORDER . '/receipt');
        $I->seeResponseCodeIs(404);
    }

    /** And a guest sees none of it. */
    public function aGuestCannotReadAReceipt(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $I->amOnPage($this->receiptUrl());

        $I->dontSeeElement('.rc--original');
        $I->seeInCurrentUrl('/login');
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

    /**
     * The page scores now, and it shows its working.
     *
     * The test this replaces asserted that no percentage existed anywhere, for a stated reason: a score
     * was an untaken business decision. It has been taken and specified, so what must be guarded is no
     * longer the absence of a number but the presence of the rules behind it — a figure a reader cannot
     * check is the thing the old test was really protecting against.
     */
    public function theScoreIsShownWithTheRulesItWasComputedBy(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Overall match score');
        // The scope, beside the figure: two documents, not a person and not a period.
        $I->see('this demo order', '.ot-score__scope');
        $I->see('How this score is calculated');

        // Every rule that moves the figure, in the page's own words.
        $I->see('silence is not agreement');
        $I->see('the quantity counts double');
        $I->see('The unit price is shown');
        $I->see('Totals are shown for reference and are not scored');
        $I->see('the payment details together, as one score');

        // The weighting, printed from the same table the arithmetic used — and as shares of a
        // hundred, which is what the sum divides by when every section applies.
        $I->see('Customer details 22.22%');
        $I->see('Order details 22.22%');
        $I->see('Delivery address 5.56%');
        $I->see('Order items 50.00%');
        $I->dontSee('Totals 5.56%');
        $I->see('100.00% in all');

        // Its scope, so a figure about two documents is not read as a figure about a person.
        $I->see('not the trainee');
    }

    /**
     * A demo order that reproduced everything comparable scores full marks.
     *
     * The fixture's original holds a phone and one line; the demo holds the same phone, the same line
     * and a first name the mirror has no counterpart for. That extra name is shown and not charged, so
     * every scored unit agreed and the figure is 100%.
     */
    public function aFaithfulReproductionScoresFull(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('100%', '.ot-score__value');
    }

    /** A wrong quantity is visible in the figure, not only in the row. */
    public function aWrongQuantityLowersTheItemSectionScore(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);

        $wrong = $this->wings();
        $wrong['qty'] = '3';
        $this->seedDemoOrder([$wrong]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        // This line has no modifiers and no instructions on either side, so its weighted units are
        // Product 1 and Quantity 2 — the unit price and product code are not scored — and the wrong
        // quantity costs two of the three: 33%. The unit tests hold the arithmetic; this holds that the
        // page shows it.
        $I->see('33%', '.ot-book--demo .ot-sscore__value');
        $I->dontSee('100%', '.ot-score__value');
    }

    /**
     * Items that were never fetched leave the order unscored rather than scored low.
     *
     * Half the weight is the item list. A figure built from the other half would be a different
     * measurement wearing this one's name, so the page says so — and the reason is the alert it already
     * shows, not a second copy of it beside the number.
     */
    public function anUnsynchronizedOriginalIsNotScoredAtAll(WebTester $I): void
    {
        // `PENDING` is what the mirror holds for an order whose items were never asked for.
        $this->seedOrder('PENDING');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        // Unavailable printed as unavailable, in words, rather than as a 0% nobody measured.
        $I->see('Not scored', '.ot-score__value');
        $I->see('see the note below', '.ot-score__scope');
        $I->seeElement('.ot-score--unknown');
    }

    /**
     * The score is on the demo panel only.
     *
     * It is a statement about what the trainee entered, like the per-row verdicts beside it. On the
     * original panel it would be a figure about the provider's own order, which is not a thing this
     * page measures.
     */
    public function theSectionScoresAreOnTheDemoPanelOnly(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->seeElement('.ot-book--demo .ot-sscore');
        $I->dontSeeElement('.ot-book--original .ot-sscore');
    }

    /**
     * An address only the demo states has nothing to be scored against.
     *
     * The original here holds no shipping block — a take-out shape — so the demo's street and city are
     * shown, nothing is charged, and the section says N/A rather than a percentage nobody measured.
     */
    public function anAddressOnlyTheDemoStatesIsNotScored(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('N/A', '.ot-book--demo .ot-sscore--none');
        $I->see('Missing in original', '.ot-book--demo');
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
     * An order naming nobody prints no name line rather than a placeholder.
     *
     * This is the common case: the default fixture reservation holds a phone and no name, exactly as
     * the mirror does for all but six of its orders.
     */
    public function anOrderWithoutANameShowsNoPlaceholder(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        // No name, so no name line — a lone dash under the heading said nothing (2026-10-30). And the
        // phone is not repeated in the heading: it is the first row under Customer details.
        $I->dontSeeElement('.ot-book--original .ot-book__who');
        $I->dontSeeElement('.ot-book__phone');
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

    /**
     * The controls that remain survive the redesign; losing either would strand the page.
     *
     * "Sync Original Order Items" was the third, and it went with the per-order items endpoint in
     * Stage 7a: it posted to a route that no longer exists, because an order's lines now arrive
     * inside the order from the Case Order response. Its absence is asserted here rather than left
     * untested, so a reintroduced button that posts nowhere fails this instead of an operator's click.
     */
    public function theExistingControlsAreStillThere(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        // Shortened on screen and whole in the accessible name, which is what a screen reader reads.
        $I->see('All Demo Orders');
        $I->seeElement('a.btn--secondary[aria-label="All demo orders for this call"]');
        $I->see('View Receipt');

        $I->dontSee('Sync Original Order Items');
        $I->dontSeeElement('form[action*="order-items/sync"]');
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
        $I->see('Missing in original', '.ot-book--demo');
        // Verdicts stay on the side that is being judged, however many items there are.
        $I->dontSee('Missing in demo', '.ot-book--original');
        $I->dontSee('Missing in original', '.ot-book--original');
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
                // Direct text only. The heading on the demo side also carries that section's score,
                // which is a statement about the demo and deliberately has no counterpart opposite —
                // the row's identity is the heading's own words.
                $label = '';

                foreach ($xpath->query('./text()', $row) as $text) {
                    $label .= $text->textContent;
                }

                $out[] = 'title:' . self::flatten($label);

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

    private function receiptUrl(): string
    {
        return $this->url() . '/receipt';
    }

    /**
     * Every row of one receipt, in the order it is drawn, named by what it is.
     *
     * Explicit about which elements count as rows rather than "every child", because an element that
     * slipped out of the shared sequence is exactly what would break the pairing, and a loose selector
     * would hide it.
     *
     * @return list<string>
     */
    private function receiptRows(string $html, string $side): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $has = static fn(string $class): string
            => "contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')";

        $card = $xpath->query('//section[' . $has('rc--' . $side) . ']')->item(0);

        if (!$card instanceof DOMElement) {
            return [];
        }

        $rows = $xpath->query(
            './*[' . $has('rc__head')
            . ' or ' . $has('rc__absent')
            . ' or ' . $has('rc__caption')
            . ' or ' . $has('rc__row')
            . ' or ' . $has('rc__line')
            . ' or ' . $has('rc__empty')
            . ' or ' . $has('rc__removed')
            . ' or ' . $has('rc__sum')
            . ' or ' . $has('rc__foot') . ']',
            $card,
        );

        $out = [];

        foreach ($rows as $row) {
            if (!$row instanceof DOMElement) {
                continue;
            }

            $class = $row->getAttribute('class');

            // A labelled row is named by its label, so the two cards must agree about which field each
            // row is — not merely about how many rows there are.
            if (str_contains($class, 'rc__row') || str_contains($class, 'rc__sum')) {
                $label = $xpath->query('.//*[' . $has('rc__label') . ']', $row)->item(0);
                $out[] = 'field:' . self::flatten($label?->textContent ?? '');

                continue;
            }

            if (str_contains($class, 'rc__caption')) {
                $out[] = 'caption:' . self::flatten($row->textContent);

                continue;
            }

            // An item names different things on the two sides — or nothing at all on one. Only its
            // position in the sequence has to match.
            $out[] = match (true) {
                str_contains($class, 'rc__line--mods') => 'mods',
                str_contains($class, 'rc__line--note') => 'note',
                str_contains($class, 'rc__line--head') => 'items-head',
                str_contains($class, 'rc__line') => 'item',
                str_contains($class, 'rc__head') => 'head',
                str_contains($class, 'rc__absent') => 'absent',
                str_contains($class, 'rc__removed') => 'removed',
                str_contains($class, 'rc__foot') => 'foot',
                default => 'other',
            };
        }

        return $out;
    }

    /**
     * What the summary strip claims, keyed by status.
     *
     * @return array<string, int>
     */
    private function chipCounts(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $out = [];

        foreach ($xpath->query('//*[@data-rc-filter]') as $chip) {
            if (!$chip instanceof DOMElement) {
                continue;
            }

            $count = $xpath->query(
                './/*[contains(concat(" ", normalize-space(@class), " "), " rc-chip__count ")]',
                $chip,
            )->item(0);

            $out[$chip->getAttribute('data-rc-filter')] = (int) self::flatten($count?->textContent ?? '0');
        }

        return $out;
    }

    /** How many primary rows of the demo receipt carry this verdict. Sub-rows repeat their item's. */
    private function rowsWithStatus(string $html, string $status): int
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $has = static fn(string $class): string
            => "contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')";

        $rows = $xpath->query(
            '//section[' . $has('rc--demo') . ']/*[@data-rc-status="' . $status . '"]'
            . '[not(' . $has('rc__line--mods') . ') and not(' . $has('rc__line--note') . ')]',
        );

        return $rows->length;
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
    private function seedOrder(
        string $itemsStatus,
        ?string $syncedAt = null,
        ?array $reservation = null,
        ?array $shipping = null,
        array $extra = [],
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $data = ['reservation' => $reservation ?? ['phone' => self::PHONE]];

        if ($shipping !== null) {
            $data['shipping'] = $shipping;
        }

        // Deliberately WITHOUT an `items` key: this is what the orders mirror actually holds, and the
        // reason the items come from their own table.
        $payload = (string) json_encode(array_replace([
            'id' => (string) self::ORDER,
            'status' => 'completed',
            'type' => 'delivery',
            'total_amount' => '13.35000',
            'data' => (string) json_encode($data),
        ], $extra));

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

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $extra top-level payload keys to add or replace
     */
    private function seedDemoOrder(
        array $items,
        ?string $address = '162 N Main St',
        ?string $city = 'Freeport',
        array $extra = [],
        ?string $postalCode = null,
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $payload = (string) json_encode(array_replace([
            'id' => self::DEMO_ORDER,
            'status' => 'completed',
            'type' => 'delivery',
            'total_amount' => '13.35000',
            'items' => $items,
            'data' => ['reservation' => ['phone' => self::PHONE, 'first_name' => 'testa']],
        ], $extra));

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
            'postal_code' => $postalCode,
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
