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

use function array_replace;
use function bin2hex;
use function gmdate;
use function hash;
use function json_decode;
use function json_encode;
use function random_bytes;
use function str_repeat;
use function time;

/**
 * Order Testing's demo orders: the column, the list, the comparison and who is recorded as testing.
 *
 * ## What is really being guarded
 *
 * The store-scoping. Three ids identify a demo order and all three come out of the URL, so the thing
 * worth proving over HTTP — not in a unit test — is that changing one of them reaches nothing. A
 * comparison page that answered for another merchant's order would be a data leak with a plausible URL.
 *
 * And the attribution. The Demo URL button stopped being a link so that who clicked it could be
 * recorded; a test that only checked the redirect would pass with the row never written.
 */
final class OrderTestingDemoOrdersCest
{
    private const ADMIN = '__kf_ot_demo_admin__';
    private const PASSWORD = 'OrderTestingDemoPassw0rd!secure';

    private const STORE_ID = 987655910;
    private const OTHER_STORE_ID = 987655911;
    private const STORE_NAME = 'Order Testing Demo Fixture Store';
    private const HOST = 'kf-demo-fixture.order58.com';

    private const SOURCE_ORDER = 16758551;
    private const QUIET_ORDER = 16754561;
    private const PHONE = '19178532637';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->seedStore(self::STORE_ID, self::STORE_NAME);
        $this->seedStore(self::OTHER_STORE_ID, self::STORE_NAME . ' (other)');
        $this->seedSourceOrder(self::STORE_ID, self::SOURCE_ORDER);
        $this->seedSourceOrder(self::STORE_ID, self::QUIET_ORDER);
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ the column

    /** The new column sits beside Practice Order and says nothing has been created yet. */
    public function theStorePageShowsADashWhenNothingHasBeenCreated(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $I->seeResponseCodeIs(200);

        // The column that opens a practice order. Renamed from "Demo URL"; same control, same
        // destination, and the Demo Orders column beside it deliberately keeps its own name.
        $I->see('Practice Order');
        $I->see('Demo Orders');
        // No link to a page that would be empty.
        $I->dontSeeElement('a[href*="/demo-orders"]');
    }

    /** Three demo orders, one link, the count in it. */
    public function theStorePageCountsTheDemoOrders(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);
        $this->seedDemoOrder(16630651);
        $this->seedDemoOrder(16630661);
        $this->seedDemoOrder(16630671);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        // The label and the count are separate elements now, so the count can be set apart from the
        // word without the two being read as one string. The accessible name carries the whole
        // sentence, which is the part a screen reader gets.
        $I->see('3', '.ot-demo-orders__count');
        $I->see('View', '.ot-demo-orders__label');
        $I->seeElement(
            'a[href="/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER
            . '/demo-orders"][aria-label="View 3 demo orders for this order"]',
        );
    }

    /** The audio page is untouched: it has neither the column nor the form. */
    public function theAudioStorePageHasNoDemoOrdersColumn(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);
        $this->seedDemoOrder(16630651);

        $I->amOnPage('/admin/order58/store-audio');
        $I->amOnPage('/audio-to-text/store/' . self::STORE_ID);
        $I->seeResponseCodeIs(200);

        $I->see('Practice Order');
        $I->dontSee('Demo Orders');
        $I->dontSeeElement('form[action*="/demo-url"]');
    }

    // ------------------------------------------------------------------ the list

    public function theListShowsOnlyThatSourceOrdersDemoOrders(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);
        $this->seedDemoOrder(16630661);
        // A demo order of a DIFFERENT source order in the same store.
        $this->seedDemoOrder(16630681, self::QUIET_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo-orders');
        $I->seeResponseCodeIs(200);

        $I->see('16630651');
        $I->see('16630661');
        $I->dontSee('16630681');
    }

    /**
     * Each demo order gets its own comparison, whatever it scored; they are never merged into one.
     *
     * None of these scores 100%, and every one is still offered — the 100%-only rule of 28 October
     * was lifted the same day. In a new tab, so the results page stays where it was.
     */
    public function eachDemoOrderLinksToItsOwnComparison(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);
        $this->seedDemoOrder(16630661);
        $this->seedDemoOrder(16630671);

        $base = '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER;
        $I->amOnPage($base . '/demo-orders');

        foreach ([16630651, 16630661, 16630671] as $demoId) {
            $I->seeElement('a[href="' . $base . '/demo/' . $demoId . '"][target="_blank"]');
        }
    }

    // ------------------------------------------------------------------ try scores

    /**
     * The table lists every try's score instead of Best and Latest, oldest first, each linking to that
     * try's own comparison in a new tab.
     */
    public function thePractitionerRowListsEveryTryScoreOldestFirst(WebTester $I): void
    {
        $this->signIn($I);
        $attemptId = $this->seedAttempt();
        $tries = [16630651, 16630661, 16630671];

        foreach ($tries as $demoId) {
            $this->seedDemoOrder($demoId, self::SOURCE_ORDER, $attemptId);
        }

        $base = '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER;
        $I->amOnPage($base . '/demo-orders');

        $I->see('Try scores', '.pr-table--practitioners thead');
        $I->dontSee('Best', '.pr-table--practitioners thead');
        $I->dontSee('Latest', '.pr-table--practitioners thead');

        // One chip per try, in try order, each to its own demo order.
        Assert::assertSame(
            [$base . '/demo/16630651', $base . '/demo/16630661', $base . '/demo/16630671'],
            $I->grabMultiple('.pr-table--practitioners .pr-chip', 'href'),
        );
        $I->dontSeeElement('.pr-table--practitioners .pr-chip:not([target="_blank"])');

        // Each shows and names its try, so identical scores are still told apart at a glance.
        $I->see('1', '.pr-chip[href="' . $base . '/demo/16630651"] .pr-chip__try');
        $I->see('3', '.pr-chip[href="' . $base . '/demo/16630671"] .pr-chip__try');
        $I->seeElement('.pr-chip[href="' . $base . '/demo/16630651"][aria-label^="Try 1 — "][aria-label*="View Comparison"]');
        $I->seeElement('.pr-chip[href="' . $base . '/demo/16630671"][aria-label^="Try 3 — Latest — "]');

        // Nothing is held back: three tries, three scores, and no "earlier" count.
        $I->dontSeeElement('.pr-table--practitioners .pr-chips__more');
    }

    /** The row's own View Comparison opens the latest try — the last score in the row. */
    public function theRowsViewComparisonOpensTheLatestTry(WebTester $I): void
    {
        $this->signIn($I);
        $attemptId = $this->seedAttempt();

        foreach ([16630651, 16630661, 16630671] as $demoId) {
            $this->seedDemoOrder($demoId, self::SOURCE_ORDER, $attemptId);
        }

        $base = '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER;
        $I->amOnPage($base . '/demo-orders');

        $I->seeElement(
            '.pr-table--practitioners td[data-ot-label="Actions"] a[data-pr-compare][href="' . $base . '/demo/16630671"][target="_blank"]',
        );
        // The latest chip is marked, and labelled Latest, exactly once.
        $I->seeElement('.pr-chip.pr-chip--latest[href="' . $base . '/demo/16630671"] .pr-chip__latest');
        $I->seeNumberOfElements('.pr-chip--latest', 1);
        $I->seeNumberOfElements('.pr-table--practitioners .pr-chip__latest', 1);
    }

    /** Five columns, and both actions together in the one Actions cell. */
    public function bothActionsShareOneActionsColumn(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651, self::SOURCE_ORDER, $this->seedAttempt());

        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo-orders');

        $I->seeNumberOfElements('.pr-table--practitioners thead th', 5);
        $I->see('Actions', '.pr-table--practitioners thead');

        $group = '.pr-table--practitioners td[data-ot-label="Actions"] .pr-actions__group';
        $I->seeElement($group . ' a[data-pr-compare]');
        $I->seeElement($group . ' a[data-pr-tries]');
        // A single try is the latest one.
        $I->seeElement('.pr-table--practitioners .pr-chip--latest .pr-chip__latest');
    }

    /** Two practitioners on one order: each row links only to its own results. */
    public function eachRowLinksOnlyToItsOwnPractitionersResults(WebTester $I): void
    {
        $this->signIn($I);
        $mine = $this->seedAttempt();
        $theirs = $this->seedAttempt('OPERATION', 424242);

        $this->seedDemoOrder(16630651, self::SOURCE_ORDER, $mine);
        $this->seedDemoOrder(16630661, self::SOURCE_ORDER, $theirs);
        $this->seedDemoOrder(16630671, self::SOURCE_ORDER, $mine);

        $base = '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER;
        $I->amOnPage($base . '/demo-orders');

        // Neither fixture result carries a creation time, so the default order falls to its tie-break —
        // realm, then id — which puts the administrator first.
        $rows = '.pr-table--practitioners tbody tr';

        Assert::assertSame(
            [$base . '/demo/16630651', $base . '/demo/16630671'],
            $I->grabMultiple($rows . ':nth-child(1) .pr-chip', 'href'),
        );
        Assert::assertSame(
            [$base . '/demo/16630661'],
            $I->grabMultiple($rows . ':nth-child(2) .pr-chip', 'href'),
        );
        // Their latest is their own, numbered from their own history.
        $I->seeElement($rows . ':nth-child(2) td[data-ot-label="Actions"] a[data-pr-compare][href="' . $base . '/demo/16630661"]');
        $I->seeElement($rows . ':nth-child(2) .pr-chip[aria-label^="Try 1 — Latest — "]');
    }

    /** The drawer offers every try's comparison, and its score links there too. */
    public function theDrawerOffersEveryTrysComparison(WebTester $I): void
    {
        $this->signIn($I);
        $attemptId = $this->seedAttempt();

        foreach ([16630651, 16630661] as $demoId) {
            $this->seedDemoOrder($demoId, self::SOURCE_ORDER, $attemptId);
        }

        $base = '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER;
        $I->amOnPage($base . '/demo-orders');
        $I->amOnPage((string) $I->grabAttributeFrom('.pr-table--practitioners [data-pr-tries]', 'href'));

        foreach ([16630651 => 'Try 1', 16630661 => 'Try 2'] as $demoId => $try) {
            $I->seeElement(
                '.pr-table--tries a.ot-rowaction[href="' . $base . '/demo/' . $demoId . '"][target="_blank"]'
                . '[aria-label="View comparison for ' . $try . '"]',
            );
            $I->seeElement('.pr-table--tries a.pr-chip[href="' . $base . '/demo/' . $demoId . '"][target="_blank"]');
        }

        // The summary above the rows is unchanged.
        $I->see('Completed tries');
    }

    /**
     * Attribution is shown only where a real attempt backs it.
     *
     * On `/demo-orders/all`, which is where the flat list lives since "View (N)" began opening the
     * practice history instead. The same property is asserted on that history page below, because
     * attribution is now printed in two places and an invented name would be equally wrong in either.
     */
    public function anUnmatchedDemoOrderIsLabelledUnmatched(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo-orders/all',
        );

        // Scoped to the table: the admin layout prints the signed-in username in the sidebar, so an
        // unscoped assertion here would be testing the chrome rather than the attribution cell.
        $I->see('Unmatched', 'table');
        $I->dontSee(self::ADMIN, 'table');
    }

    public function aMatchedDemoOrderNamesTheAdministrator(WebTester $I): void
    {
        $this->signIn($I);
        $attemptId = $this->seedAttempt();
        $this->seedDemoOrder(16630651, self::SOURCE_ORDER, $attemptId);

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo-orders/all',
        );

        $I->see(self::ADMIN, 'table');
    }

    /**
     * The history page keeps an unowned demo order in its own section and names nobody.
     *
     * The important half is the second assertion. A card that fell into somebody's group — or that
     * borrowed the signed-in reader's name because it was convenient — would be exactly the invented
     * training record the importer refuses to create.
     */
    public function thePracticeHistoryKeepsAnUnmatchedDemoOrderUnowned(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo-orders');

        /*
         * Its own section, and no practitioner row at all.
         *
         * The grouped cards became a table of practitioners, so the assertion moved with them: an
         * unattributed result must appear under Unmatched and must leave the practitioner table
         * empty — which is stronger than it not carrying a name, because the row does not exist.
         */
        $I->see('Unmatched', '.pr-unmatched');
        $I->see('No completed practice results yet');
        $I->dontSeeElement('.pr-table .pr-agent');
    }

    /** And an attributed one is named, under its own practitioner's heading. */
    public function thePracticeHistoryNamesTheAdministratorWhoPractised(WebTester $I): void
    {
        $this->signIn($I);
        $attemptId = $this->seedAttempt();
        $this->seedDemoOrder(16630651, self::SOURCE_ORDER, $attemptId);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo-orders');

        // Named in their own row of the practitioner table, with the one result counted.
        $I->see(self::ADMIN, '.pr-table .pr-agent');
        $I->see('1', '.pr-table .pr-num');

        // And their tries are one click away rather than expanded into the page.
        $I->seeElement('.pr-table [data-pr-tries]');
    }

    // ------------------------------------------------------------------ the comparison

    public function theComparisonShowsBothOrdersSideBySide(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/16630651',
        );
        $I->seeResponseCodeIs(200);

        $I->see('Original order');
        $I->see('Demo / test order');
        $I->see('Customer');
        $I->see('Totals');
        $I->see('Order items');
        // Both halves really rendered, not just the headings.
        $I->see('Wonton Soup');
    }

    /** The fixture demo order differs from the source on purpose; the page must say so. */
    public function theComparisonReportsADifferenceItWasGiven(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651, self::SOURCE_ORDER, null, '9.99000');

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/16630651',
        );

        $I->see('Different');
        $I->see('9.99000');
        $I->see('4.28000');
    }

    /**
     * The page scores, and what it shows is a measurement rather than a verdict.
     *
     * This test used to assert that no percentage existed anywhere, because scoring was a business
     * decision nobody had taken. It has since been taken and specified, so what is guarded here is what
     * that decision was: a figure with its scope stated and its rules on the page, and still no pass
     * mark, grade or judgement about the person who typed the order.
     */
    public function theComparisonScoresWithoutPassingJudgement(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/16630651',
        );

        $I->seeElement('.ot-score');
        // This fixture's original order has no synchronised items, and the item list is half the
        // weighting — so the honest answer is that it is not scored, not a figure built from the other
        // half. `OrderTestingItemComparisonCest` covers the synchronised case and its percentages.
        $I->see('Not scored', '.ot-score__value');
        // Where a scored page states its scope, this one states where the reason is: the
        // item-synchronisation alert further down, rather than a second copy of it up here.
        $I->see('see the note below', '.ot-score__scope');
        // And the rules, so a reader can check whatever figure does appear.
        $I->see('How this score is calculated');

        // A measurement, not a verdict. These words would make it one.
        $I->dontSee('Pass');
        $I->dontSee('Fail');
        $I->dontSee('Grade');
        $I->dontSee('Accuracy');
    }

    /** A demo order whose source order was never mirrored stays fully visible. */
    public function aDemoOrderSurvivesAMissingSourceOrder(WebTester $I): void
    {
        $this->signIn($I);
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => self::STORE_ID])->execute();
        $this->seedDemoOrder(16630651);

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/16630651',
        );
        $I->seeResponseCodeIs(200);

        $I->see('Source order unavailable in the local mirror');
        // The trainee's work is still all there.
        $I->see('Wonton Soup');
        $I->see('Not available');
    }

    // ------------------------------------------------------------------ payment details

    /**
     * Both pages show how each order was paid: the method, the card's last four digits and the
     * processing fee — and nothing else about the card. Synthetic values throughout.
     */
    public function bothPagesShowPaymentDetailsAndNoCardSecrets(WebTester $I): void
    {
        $this->signIn($I);
        $this->payByCard(self::SOURCE_ORDER, '4242', '0.85110', 'America/New_York');
        $this->seedDemoOrder(16630651);
        $this->payDemoByCard(16630651, null, '0.07468', 'America/Chicago');

        $base = '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/16630651';

        foreach ([$base, $base . '/receipt'] as $page) {
            $I->amOnPage($page);

            $I->see('Payment', $page === $base ? '.ot-book__subhead' : '.rc__caption');
            $I->see('credit card');
            // The original kept its digits; the demo was paid by card but kept none.
            $I->see('•••• 4242');
            $I->see('Card not recorded');
            $I->see('rocessing fee');

            // Never a secret, never a symbol for a verdict.
            foreach (['card_num', 'cvv', 'exp_date', 'billing_zip', 'U1lOVEhF', '✓', '✕', '≠'] as $absent) {
                $I->dontSeeInSource($absent);
            }
        }

        // The receipt prints each order on its own store clock.
        $I->see('EDT', '.rc--original .rc__date');
        $I->see('CDT', '.rc--demo .rc__date');
    }

    /** A cash order on both sides has no card row and no fee row. */
    public function aCashOrderShowsItsMethodWithoutCardFields(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);

        $base = '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/16630651';

        foreach ([$base => '.ot-books', $base . '/receipt' => '.rc-pair'] as $page => $documents) {
            $I->amOnPage($page);
            $I->see('cash', $documents);
            $I->dontSee('Card not recorded', $documents);
            $I->dontSee('••••', $documents);
            // On the documents themselves: the scoring note below them mentions the fee in prose.
            $I->dontSee('rocessing fee', $documents);
        }
    }

    /** The summary card names the comparison, not the plumbing; the verdicts are words, not symbols. */
    public function theComparisonCarriesNoTechnicalDetail(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651, self::SOURCE_ORDER, $this->seedAttempt());

        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/16630651');

        $I->dontSee('Imported from');
        $I->dontSee('.json');
        $I->dontSee('Original items', '.ot-summary');
        $I->dontSee('(Matched)', '.ot-summary');
        $I->dontSee('(Expired)', '.ot-summary');
        $I->see(self::ADMIN, '.ot-summary');
        $I->dontSeeElement('.ot-book__phone');
        $I->dontSeeElement('.ot-mark__glyph');
        $I->seeElement('details.ot-books__note');
    }

    // ------------------------------------------------------------------ the boundary

    public function aDemoOrderCannotBeReachedThroughAnotherStore(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);

        $I->amOnPage(
            '/order-testing/store/' . self::OTHER_STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/16630651',
        );
        $I->seeResponseCodeIs(404);
    }

    public function aDemoOrderCannotBeReachedThroughAnotherSourceOrder(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::QUIET_ORDER . '/demo/16630651',
        );
        $I->seeResponseCodeIs(404);
    }

    public function anUnknownDemoOrderIsNotFound(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/99999999',
        );
        $I->seeResponseCodeIs(404);
    }

    public function aGuestSeesNoneOfIt(WebTester $I): void
    {
        $base = '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER;

        foreach ([$base . '/demo-orders', $base . '/demo/16630651'] as $path) {
            $I->amOnPage($path);
            $I->seeCurrentUrlEquals('/login');
        }
    }

    // ------------------------------------------------------------------ the Demo URL click

    /**
     * The JSON answer the page script reads: the attempt is written, then the URL is handed back.
     *
     * No redirect, and that is the fix rather than an accident. `form-action 'self'` is enforced
     * across redirects by Chrome, so a 303 to order58.com left the new tab on about:blank. A URL the
     * script navigates a tab to is not a form submission and is not restricted.
     */
    public function theJsonEndpointRecordsTheAttemptAndReturnsTheUrl(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $token = $I->grabAttributeFrom('form[action*="/demo-url"] input[name="_csrf"]', 'value');

        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest('/order-testing/store/' . self::STORE_ID . '/demo-url', [
            'source_order_id' => (string) self::SOURCE_ORDER,
            '_csrf' => $token,
        ]);

        $I->seeResponseCodeIs(200);

        // Decoded, not matched as text. `json_encode` escapes forward slashes here — the house style
        // keeps that — so a substring assertion would be testing the encoder's escaping rather than
        // the URL. Built server-side from the mirrored host and phone, through the host allow-list.
        Assert::assertSame(
            ['url' => 'https://' . self::HOST . '/admin/demo/order/make/'
                . self::SOURCE_ORDER . '-' . self::PHONE],
            $this->payload($I),
        );

        $row = (new Query($this->connection))
            ->select(['initiated_by_type', 'initiated_by_id', 'status'])
            ->from('{{%order_testing_attempts}}')
            ->where(['store_source_id' => self::STORE_ID, 'source_order_id' => self::SOURCE_ORDER])
            ->one();

        Assert::assertIsArray($row, 'the attempt must be written before the URL is handed back');
        Assert::assertSame('ADMIN', (string) $row['initiated_by_type']);
        Assert::assertSame('STARTED', (string) $row['status']);
        Assert::assertSame($this->adminId(), (int) $row['initiated_by_id']);
    }

    /**
     * Nothing in the request body can choose the destination.
     *
     * Without this the endpoint would be an open redirect that hands a customer's phone number to
     * whoever picked the host.
     */
    public function noPostedFieldCanChangeTheDestination(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $token = $I->grabAttributeFrom('form[action*="/demo-url"] input[name="_csrf"]', 'value');

        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest('/order-testing/store/' . self::STORE_ID . '/demo-url', [
            'source_order_id' => (string) self::SOURCE_ORDER,
            '_csrf' => $token,
            'url' => 'https://evil.example.com/steal',
            'host' => 'evil.example.com',
            'redirect' => 'https://evil.example.com/steal',
            'return_to' => 'https://evil.example.com/steal',
        ]);

        $I->seeResponseCodeIs(200);

        $payload = $this->payload($I);

        Assert::assertSame(
            'https://' . self::HOST . '/admin/demo/order/make/' . self::SOURCE_ORDER . '-' . self::PHONE,
            $payload['url'] ?? null,
        );
        $I->dontSeeInSource('evil.example.com');
    }

    /**
     * The actor cannot be chosen by the browser.
     *
     * The identity comes from the authenticated session; a posted `initiated_by_id` is ignored, which
     * is what stops one operator attributing their work to somebody else.
     */
    public function thePostedActorIdIsIgnored(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $token = $I->grabAttributeFrom('form[action*="/demo-url"] input[name="_csrf"]', 'value');

        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest('/order-testing/store/' . self::STORE_ID . '/demo-url', [
            'source_order_id' => (string) self::SOURCE_ORDER,
            '_csrf' => $token,
            'initiated_by_id' => '999999',
            'initiated_by_type' => 'OPERATION',
        ]);

        $row = (new Query($this->connection))
            ->select(['initiated_by_type', 'initiated_by_id'])
            ->from('{{%order_testing_attempts}}')
            ->where(['store_source_id' => self::STORE_ID, 'source_order_id' => self::SOURCE_ORDER])
            ->one();

        Assert::assertIsArray($row);
        Assert::assertSame($this->adminId(), (int) $row['initiated_by_id']);
        Assert::assertSame('ADMIN', (string) $row['initiated_by_type']);
    }

    /**
     * A second person practising the same order gets their own attempt, not a 409.
     *
     * Inverted deliberately: this used to assert a refusal, because attribution relied on only one
     * person holding a source order at a time. Concurrent practice is the business rule now, so the
     * second click succeeds and two tracked rows exist — and the ambiguity that creates is paid for
     * at import, where such a demo order is left Unmatched rather than credited to a guess.
     */
    public function aSecondPersonGetsTheirOwnAttemptRatherThanAConflict(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        // Somebody else is already practising this order.
        $this->connection->createCommand()->insert('{{%order_testing_attempts}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => self::STORE_ID,
            'source_order_id' => self::SOURCE_ORDER,
            'initiated_by_type' => 'ADMIN',
            'initiated_by_id' => $this->adminId() + 9_000_000,
            'status' => 'STARTED',
            'started_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $token = $I->grabAttributeFrom('form[action*="/demo-url"] input[name="_csrf"]', 'value');

        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest('/order-testing/store/' . self::STORE_ID . '/demo-url', [
            'source_order_id' => (string) self::SOURCE_ORDER,
            '_csrf' => $token,
        ]);

        $I->seeResponseCodeIs(200);
        // The destination is returned, so the browser has somewhere to go. Matched on the JSON key
        // and the host rather than on the path: this is a JSON body, so its slashes are escaped and
        // a literal `/admin/demo/order/make/` never appears in the source.
        $I->seeInSource('"url"');
        $I->seeInSource('order58.com');
        $I->dontSeeInSource('already testing this order');

        // Two people, two independently tracked rows.
        Assert::assertSame(2, (int) (new Query($this->connection))
            ->from('{{%order_testing_attempts}}')
            ->where([
                'store_source_id' => self::STORE_ID,
                'source_order_id' => self::SOURCE_ORDER,
                'status' => ['STARTED', 'MATCHED'],
            ])
            ->count());
    }

    /** An order of another store cannot be started from this store's page. */
    public function anOrderOfAnotherStoreCannotBeStarted(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $token = $I->grabAttributeFrom('form[action*="/demo-url"] input[name="_csrf"]', 'value');

        // The order exists, but under STORE_ID — asked for as OTHER_STORE_ID it must not resolve.
        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest('/order-testing/store/' . self::OTHER_STORE_ID . '/demo-url', [
            'source_order_id' => (string) self::SOURCE_ORDER,
            '_csrf' => $token,
        ]);

        $I->seeResponseCodeIs(422);
        $I->dontSeeInSource('/admin/demo/order/make/');

        Assert::assertSame(0, (int) (new Query($this->connection))
            ->from('{{%order_testing_attempts}}')
            ->where(['store_source_id' => self::OTHER_STORE_ID])
            ->count());
    }

    /**
     * The no-JavaScript path still works, and still records the attempt.
     *
     * The form's `target="_blank"` opens the tab; this page renders in it with a plain link, which is
     * not a form submission and so is not caught by `form-action`.
     */
    public function withoutJavaScriptTheFormAnswersWithALinkPage(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $I->submitForm('form[action="/order-testing/store/' . self::STORE_ID . '/demo-url"]', []);

        $I->seeResponseCodeIs(200);
        $I->see('continue to Order58');
        $I->seeElement(
            'a[href="https://' . self::HOST . '/admin/demo/order/make/'
            . self::SOURCE_ORDER . '-' . self::PHONE . '"]',
        );

        Assert::assertSame(1, (int) (new Query($this->connection))
            ->from('{{%order_testing_attempts}}')
            ->where(['store_source_id' => self::STORE_ID])
            ->count());
    }

    /** Without CSRF the click writes nothing. */
    public function theDemoUrlPostRequiresCsrf(WebTester $I): void
    {
        $this->signIn($I);

        $I->sendAjaxPostRequest('/order-testing/store/' . self::STORE_ID . '/demo-url', [
            'source_order_id' => (string) self::SOURCE_ORDER,
        ]);

        Assert::assertSame(0, (int) (new Query($this->connection))
            ->from('{{%order_testing_attempts}}')
            ->where(['store_source_id' => self::STORE_ID])
            ->count());
    }

    /** The markup the script binds to, so a rename cannot silently unhook it. */
    public function theDemoUrlButtonCarriesItsScriptHook(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $I->seeElement('form[action="/order-testing/store/' . self::STORE_ID . '/demo-url"]');
        $I->seeElement('button[data-ot-demo-url]');
        $I->seeElement('[data-ot-demo-message]');
        // The script that binds them is loaded, and is this surface's own file.
        $I->seeElement('script[src*="order-testing"]');
    }

    // ------------------------------------------------------------------ fixtures

    /**
     * The JSON body, decoded.
     *
     * @return array<string, mixed>
     */
    private function payload(WebTester $I): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($I->grabPageSource(), true);

        Assert::assertIsArray($decoded, 'the endpoint must answer with a JSON object');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function signIn(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
    }

    private function adminId(): int
    {
        return (int) (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::ADMIN])->scalar();
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

        // The store page resolves a store through `knowledge_bases`, not `order58_stores` — the name
        // it shows is that table's. Seeding only the mirror sent every store-page test to the picker,
        // which is what the first run of this suite actually proved.
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => $name,
            'slug' => 'kf-ot-demo-' . $sourceId,
            'source_system' => 'order58',
            'source_store_id' => $sourceId,
            'source_name' => $name,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** The mirrored ORIGINAL order — the left-hand side of every comparison. */
    private function seedSourceOrder(int $storeId, int $orderId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $payload = (string) json_encode($this->orderDocument($orderId, '4.28000'));

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
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedDemoOrder(
        int $demoOrderId,
        int $sourceOrderId = self::SOURCE_ORDER,
        ?int $attemptId = null,
        string $total = '4.28000',
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $payload = (string) json_encode($this->orderDocument($demoOrderId, $total));

        $this->connection->createCommand()->insert('{{%order_testing_demo_orders}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => self::STORE_ID,
            'source_order_id' => $sourceOrderId,
            'demo_order_id' => $demoOrderId,
            'matched_attempt_id' => $attemptId,
            'customer_phone' => self::PHONE,
            'customer_first_name' => 'testa',
            'order_type' => 'delivery',
            'payment_method' => 'cash',
            'status' => 'completed',
            'total_amount' => $total,
            'address' => '162 N Main St',
            'city' => 'Freeport',
            'source_filename' => $sourceOrderId . '-' . $demoOrderId . '.json',
            'content_hash' => hash('sha256', $payload),
            'raw_payload' => $payload,
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** An attempt by this suite's administrator, or by whoever is named. */
    private function seedAttempt(string $realm = 'ADMIN', ?int $actorId = null): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order_testing_attempts}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => self::STORE_ID,
            'source_order_id' => self::SOURCE_ORDER,
            'initiated_by_type' => $realm,
            'initiated_by_id' => $actorId ?? $this->adminId(),
            'status' => 'MATCHED',
            'started_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    /** The mirrored source order, paid by card: synthetic last four digits, fee and store time zone. */
    private function payByCard(int $orderId, ?string $lastFour, string $fee, string $timezone): void
    {
        $payload = array_replace($this->orderDocument($orderId, '4.28000'), [
            'payment_method' => 'credit card',
            'cc_processing_fee' => $fee,
            'timezone' => $timezone,
            'created_at' => 1783379248,
        ], $lastFour === null ? [] : ['card_last4' => $lastFour]);

        $this->connection->createCommand()->update(
            '{{%order58_orders}}',
            ['payment_method' => 'credit card', 'payload_json' => (string) json_encode($payload)],
            ['account_id' => self::STORE_ID, 'source_order_id' => $orderId],
        )->execute();
    }

    private function payDemoByCard(int $demoOrderId, ?string $lastFour, string $fee, string $timezone): void
    {
        $payload = array_replace($this->orderDocument($demoOrderId, '4.28000'), [
            'payment_method' => 'credit card',
            'cc_processing_fee' => $fee,
            'timezone' => $timezone,
            'created_at' => 1783379248,
        ], $lastFour === null ? [] : ['card_last4' => $lastFour]);

        $this->connection->createCommand()->update(
            '{{%order_testing_demo_orders}}',
            ['payment_method' => 'credit card', 'raw_payload' => (string) json_encode($payload)],
            ['store_source_id' => self::STORE_ID, 'demo_order_id' => $demoOrderId],
        )->execute();
    }

    /** A conversation with an order number, so the store page renders a row for it. */
    private function seedCall(int $orderId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $conversationId = bin2hex(random_bytes(16));

        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => $conversationId,
            'store_source_id' => self::STORE_ID,
            'mode' => 'COMMON',
            'recording_type' => 'MIXED',
            'order_id' => (string) $orderId,
            'generate_ai_audio' => 0,
            'uploaded_by_admin_id' => $this->adminId(),
            'created_at' => $now,
        ])->execute();

        $rowId = (int) (new Query($this->connection))
            ->select('id')->from('{{%audio_conversations}}')
            ->where(['public_id' => $conversationId])->scalar();

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'conversation_id' => $rowId,
            'uploaded_by_admin_id' => $this->adminId(),
            'original_filename' => 'order-testing.wav',
            'source_role' => 'COMMON',
            'status' => 'NOT_REQUESTED',
            'processing_stage' => 'QUEUED',
            'transcription_provider' => 'WHISPER',
            'duration_seconds' => 12.5,
            'review_count' => 0,
            'created_at' => $now,
        ])->execute();
    }

    /** @return array<string, mixed> */
    private function orderDocument(int $id, string $total): array
    {
        return [
            'id' => $id,
            'account_id' => self::STORE_ID,
            'is_test' => '1',
            'status' => 'completed',
            'type' => 'delivery',
            'payment_method' => 'cash',
            'total_amount' => $total,
            'data' => (string) json_encode([
                'reservation' => ['first_name' => 'testa', 'phone' => self::PHONE],
                'shipping' => ['city' => 'Freeport', 'state' => 'NY', 'zipcode' => '11520'],
            ]),
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
            ->delete('{{%order58_orders}}', ['account_id' => $stores])->execute();

        if ($adminIds !== []) {
            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['uploaded_by_admin_id' => $adminIds])->execute();
            $this->connection->createCommand()
                ->delete('{{%audio_conversations}}', ['uploaded_by_admin_id' => $adminIds])->execute();
            $this->connection->createCommand()
                ->delete('{{%admin_users}}', ['id' => $adminIds])->execute();
        }

        $this->connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['source_store_id' => $stores])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => $stores])->execute();
    }
}
