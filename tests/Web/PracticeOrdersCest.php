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

use function dechex;
use function gmdate;
use function str_pad;
use function str_repeat;

use const STR_PAD_LEFT;

/**
 * Practice Orders for an administrator: the picker, the store page's two new columns, and assignment.
 *
 * What is worth proving here is that this surface really is the Order Testing page with a topic on it
 * — the same table, the same conversation cell — rather than a copy that will drift. So the assertions
 * are about the columns that are new and the links that differ, and the Order Testing suite next door
 * proves the shared half still renders as it did.
 *
 * Fixture store ids sit in the `9876559xx` range, far outside the mirrored range, and cleanup is scoped
 * to this suite's own rows.
 */
final class PracticeOrdersCest
{
    private const USERNAME = '__kf_practice_admin__';
    private const PASSWORD = 'PracticeTestPassw0rd!secure';
    private const STORE_ID = 987655820;
    private const STORE_NAME = 'Practice Orders Fixture Store';
    private const ORDER = 16800401;
    /** A second order, so a filter can be proved to narrow rather than merely to render. */
    private const ORDER_B = 16800402;
    /**
     * A recording whose order id matches no row in `order58_orders` — the `#111` case from the live
     * store. It is a real shape in this database, not a contrivance: the order id is free text typed
     * at upload, so it can name an order that was never mirrored.
     */
    private const ORPHAN_ORDER = 16800999;
    private const TOPIC = 'ZZ Practice Topic';
    private const TOPIC_B = 'ZZ Practice Topic Empty';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->seedStore();
        $this->seedOrder(self::ORDER);
        $this->seedOrder(self::ORDER_B);
        $this->seedRecording(self::ORDER, 'c', 'd');
        $this->seedRecording(self::ORDER_B, 'e', 'f');
        // Deliberately no seedOrder() for this one.
        $this->seedRecording(self::ORPHAN_ORDER, '1', '2');

        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function thePickerListsStoresAndLinksIntoPracticeOrders(WebTester $I): void
    {
        $I->amOnPage('/practice-orders?q=' . urlencode(self::STORE_NAME));

        $I->seeResponseCodeIs(200);
        $I->see('Practice Orders', '.page-header__title');
        $I->see(self::STORE_NAME, '.store-grid');
        $I->see('View Practice Orders', '.store-grid');
        $I->seeElement('.store-grid a[href="/practice-orders/store/' . self::STORE_ID . '"]');
    }

    /** The picker shares its body with Order Testing, so the filters must be the same ones. */
    public function thePickerCarriesTheSameFiltersAsOrderTesting(WebTester $I): void
    {
        $I->amOnPage('/practice-orders');

        $I->seeElement('input[name="q"]');
        $I->seeElement('.filter-bar .filter-chip');
        $I->seeElement('.alpha-nav');
    }


    /** Its links must come back to Practice Orders, not wander into Order Testing. */
    public function theStorePageLinksBackToItsOwnSurface(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        // Scoped to the page's own header: the sidebar links to Order Testing on every page, so an
        // unscoped assertion would be testing the menu rather than this surface.
        $I->seeElement('.page-header a[href="/practice-orders"]');
        $I->dontSeeElement('.page-header a[href="/order-testing"]');
    }













    // ---------------------------------------------------------------- the topic details modal

    /**
     * **The whole point of the restructure.** This page shows training content; it does not manage it.
     *
     * Asserted by absence in the DOM rather than by looking at styles: a control that is present and
     * merely hidden is a control, and the endpoints behind these are not mounted for this surface
     * either. Each one of them is on Order Testing, where the administrator already was.
     */
    public function thePracticeSurfaceOffersNoManagementControl(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);
        $I->seeResponseCodeIs(200);

        // Topic management.
        $I->dontSeeElement('[data-practice-assign-row]');
        $I->dontSeeElement('[data-practice-assign-select]');
        $I->dontSeeElement('[data-practice-select]');
        $I->dontSeeElement('[data-practice-select-all]');
        $I->dontSeeElement('#practice-bulk-form');
        $I->dontSeeElement('#practice-confirm-dialog');

        // Verification. An order is signed off where it is managed, which is Order Testing; a training
        // surface shows what a call was about and says nothing about whether somebody has checked it.
        $I->dontSeeElement('[data-ot-verify]');
        $I->dontSeeElement('[data-ot-verify-dialog]');
        $I->dontSeeElement('#ot-verify-dialog');
        $I->dontSee('Verification', 'thead');

        // Audio and transcript management.
        $I->dontSeeElement('[data-a2t-open="a2t-upload-dialog"]');
        $I->dontSeeElement('#a2t-upload-dialog');
        $I->dontSeeElement('#a2t-manage-dialog');
        $I->dontSeeElement('#a2t-tts-confirm-dialog');
        $I->dontSeeElement('#a2t-transcribe-dialog');
        $I->dontSeeElement('[data-a2t-review-token]');
        $I->dontSeeElement('[data-a2t-full-editor]');
        $I->dontSeeElement('[data-a2t-details-full]');
        $I->dontSee('Sync demo orders');
        $I->dontSee('Add Audio');
    }

    /** What it does keep: the listing, the topic, and the one training action. */
    public function thePracticeSurfaceKeepsTheTrainingControls(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->see('Order No#', 'thead');
        $I->see('Topic', 'thead');
        $I->dontSee('Set Topic', 'thead');
        $I->see('Practice Order', 'thead');
        $I->see('Demo Orders', 'thead');
        $I->seeElement('[data-a2t-play]');
        $I->seeElement('[data-a2t-details]');
        $I->seeElement('.practice-topic-cell .practice-topic__value');
        $I->seeElement('.practice-filter');
    }

    // ---------------------------------------------------------------- audio-only, and Seq

    /**
     * Practice Orders shows the provider's order number, the same one Manage Orders shows.
     *
     * Seq and View Receipt arrived together and were gated together, which meant this surface could
     * have neither. They are different decisions: a sequence number is one more fact about an order
     * the reader is already looking at, while a receipt calls a third party and shows a customer's
     * printed order.
     */
    public function thePracticeSurfaceShowsSeqButNotTheReceipt(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->see('Seq', 'thead');
        $I->dontSee('View Receipt', 'thead');
        $I->dontSeeElement('a.ot-receipt');
    }

    /**
     * The conversation is not readable as text here — and it is absent, not hidden.
     *
     * Practice Orders exists so somebody can hear a call and rebuild the order from it. The written
     * transcript beside it is the answer sheet, so none of it is rendered: no "View conversation"
     * control, no container for the script to fill with turns, and no way through to the original
     * transcript. Asserted on the markup, because `display: none` would still put the words in the
     * document for anyone with an element inspector.
     */
    public function thePracticeSurfaceRendersNoConversationText(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->dontSee('View conversation');
        // The turn container the script fills. Absent, so no turn is ever built.
        $I->dontSeeElement('[data-a2t-review-scroll]');
        // And the door to the full original transcript.
        $I->dontSeeElement('[data-a2t-review-transcripts]');
    }

    /** Taking the words away must not take the audio with them. */
    public function thePracticeSurfaceKeepsItsAudio(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->seeElement('[data-a2t-play]');
        // The dialog still exists and still carries its player area and its channel tabs.
        $I->seeElement('#a2t-review-dialog');
        $I->seeElement('[data-a2t-listen]');
        $I->seeElement('[data-a2t-review-tabs]');
    }

    /** Order Testing keeps everything, and gains the management controls. */
    public function orderTestingKeepsItsManagementControls(WebTester $I): void
    {
        $this->seedTopic();

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $I->seeResponseCodeIs(200);

        $I->seeElement('[data-practice-assign-select]');
        $I->seeElement('[data-practice-select-all]');
        $I->seeElement('#practice-bulk-form');
        $I->seeElement('#practice-confirm-dialog');
        $I->seeElement('[data-ot-verify]');
        $I->seeElement('#ot-verify-dialog');
        $I->see('Set Topic', 'thead');
        $I->see('Verification', 'thead');
        $I->see('Sync demo orders');
        $I->see('Add Audio');
    }

    /**
     * Manage Orders keeps the whole conversation. This is the regression half of the change above.
     *
     * The transcript was removed from Practice Orders by taking markup out of a template those two
     * surfaces SHARE, so the only thing standing between that change and Manage Orders losing its
     * conversation is the capability flag. This asserts the flag works.
     */
    public function orderTestingKeepsItsConversation(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $I->seeElement('[data-a2t-review-scroll]');
        $I->seeElement('[data-a2t-review-transcripts]');
        $I->seeElement('[data-a2t-listen]');
        // And its own columns, unchanged.
        $I->see('Seq', 'thead');
        $I->see('View Receipt', 'thead');
    }

    /** The label changed; the destination did not. */
    public function theRenamedColumnKeepsItsOriginalDestination(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $I->see('Practice Order', 'thead');
        $I->dontSee('Demo URL', 'thead');
        // The label moved; nothing else did. This fixture's store resolves no demo host, so no link
        // renders for any row here — the destination itself is covered where a link exists, in
        // OrderTestingDemoOrdersCest, which this change does not touch.
    }

    // ---------------------------------------------------------------- practice attempts

    /**
     * "View (N)" now opens the history rather than the flat list of imports.
     *
     * The route name is unchanged, so every link that already pointed here keeps working — the table
     * cell, the comparison page's back link and the agent surface's own copy all land on the new page
     * without being edited.
     */
    public function theDemoOrdersViewOpensThePracticeAttemptsPage(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::ORDER . '/demo-orders');

        $I->seeResponseCodeIs(200);
        $I->see('Practice Attempts', 'h1');
        $I->see('Back to Store');
    }

    /** The flat list it used to open is still there, one click away. */
    public function theFlatDemoOrderListIsStillReachable(WebTester $I): void
    {
        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::ORDER . '/demo-orders/all',
        );

        $I->seeResponseCodeIs(200);
        $I->see('Demo orders for #' . self::ORDER, 'h1');
    }

    /** And the history page links to it, so the old view is never stranded. */
    public function thePracticeAttemptsPageLinksToTheFullList(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::ORDER . '/demo-orders');

        $I->seeElement(
            'a[href="/order-testing/store/' . self::STORE_ID . '/order/' . self::ORDER
            . '/demo-orders/all"]',
        );
    }

    /**
     * Retry goes through the tracked Practice Order endpoint, not straight to Order58.
     *
     * A link to the external destination would start an untracked attempt — nobody would own the
     * demo order it produced, and the concurrency rule that stops two people holding one source
     * order would never run. So it is a form, posting where the table's own button posts.
     */
    public function retryPracticePostsToTheTrackedPracticeOrderEndpoint(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::ORDER . '/demo-orders');

        $I->seeElement(
            'form.pa-retry[method="post"][action="/order-testing/store/' . self::STORE_ID . '/demo-url"]',
        );
        // The external destination is never named in the page: it is resolved server-side, by the
        // endpoint, after the attempt has been recorded.
        $I->dontSee('order58.com');
    }

    /**
     * And it opens the destination in a second tab, leaving this page where it is.
     *
     * Asserted on the markup because that is the path this suite exercises: PhpBrowser runs no
     * script, so what it sees is the native submission — which is exactly the case the attribute
     * exists for. With the script present the tab is opened by `window.open` inside the click
     * instead, which only a browser test can show.
     *
     * `rel` is asserted with it. The Order58 address carries a customer's phone number in its path,
     * so a missing `noreferrer` would hand this page's URL to the provider as the Referer.
     */
    public function retryPracticeOpensTheProviderInASecondTab(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::ORDER . '/demo-orders');

        $I->seeElement('form.pa-retry[target="_blank"][rel="noopener noreferrer"]');
    }

    /**
     * The button says Start, not Retry, until the reader has finished this order themselves.
     *
     * Same endpoint either way — this is a label, not a permission. But calling a first attempt a
     * retry is untrue, and an administrator reading somebody else's completed history has not
     * practised it. This fixture has no imported result attributed to the signed-in administrator.
     */
    public function theActionReadsStartUntilTheReaderHasFinishedThisOrder(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::ORDER . '/demo-orders');

        $I->see('Start Practice', '.pa-retry');
        $I->dontSee('Retry Practice');
    }

    /** And it becomes Retry once one of their own attempts has an imported demo order against it. */
    public function theActionReadsRetryOnceTheReaderHasAnImportedResult(WebTester $I): void
    {
        $attemptId = $this->seedAttemptFor('ADMIN', $this->adminId());
        $this->seedDemoOrderFor($attemptId);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::ORDER . '/demo-orders');

        $I->see('Retry Practice', '.pa-retry');
        $I->dontSee('Start Practice');
    }

    /** The summary carries the score and no comparison: the answer is one deliberate click away. */
    public function theAttemptCardsLinkOutToTheExistingComparisonInANewTab(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::ORDER . '/demo-orders');

        // No side-by-side invoice is drawn into the page itself.
        $I->dontSeeElement('.ot-compare');
        $I->dontSee('ORIGINAL ORDER');
    }

    // ---------------------------------------------------------------- the multi-topic filter

    /** One topic, chosen as a set of one. */
    public function filteringByOneTopicNarrowsTheList(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topics[]=' . $topicId);

        $I->seeResponseCodeIs(200);
        $I->see((string) self::ORDER, 'tbody');
        $I->dontSee((string) self::ORDER_B, 'tbody');
    }

    /** **OR across the selection**: an order matches if it carries any one of the chosen topics. */
    public function filteringBySeveralTopicsMatchesAnyOfThem(WebTester $I): void
    {
        $first = $this->seedTopic();
        $second = $this->seedTopic('ACTIVE', self::TOPIC_B);
        $this->assignDirectly($first);
        $this->assignDirectly($second, self::ORDER_B);

        $I->amOnPage(
            '/practice-orders/store/' . self::STORE_ID . '?topics[]=' . $first . '&topics[]=' . $second,
        );

        $I->see((string) self::ORDER, 'tbody');
        $I->see((string) self::ORDER_B, 'tbody');
    }

    /** A topic together with "not assigned", which is one choice in the same list. */
    public function filteringByATopicAndUnassignedTogether(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topics[]=' . $topicId . '&topics[]=none');

        $I->see((string) self::ORDER, 'tbody');
        $I->see((string) self::ORDER_B, 'tbody');
    }

    public function filteringByUnassignedAloneShowsOnlyUntopickedOrders(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topics[]=none');

        $I->dontSee((string) self::ORDER, 'tbody');
        $I->see((string) self::ORDER_B, 'tbody');
    }

    /** A hand-edited or stale value filters nothing rather than erroring. */
    public function anUnrecognisedFilterValueShowsEveryOrder(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topics[]=not-a-topic');

        $I->seeResponseCodeIs(200);
        $I->see((string) self::ORDER, 'tbody');
        $I->see((string) self::ORDER_B, 'tbody');
    }

    /**
     * The control is checkboxes and an Apply button, and nothing submits on its own.
     *
     * PhpBrowser runs no script, so what is asserted is the shape the behaviour depends on: a GET form
     * with a real submit, and no `onchange` anywhere near the choices.
     */
    public function theFilterIsCheckboxesAndAnExplicitApply(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->seeElement('form.practice-filter[method="get"]');
        $I->seeElement('.practice-filter input[type="checkbox"][name="topics[]"][value="none"]');
        $I->seeElement('.practice-filter input[type="checkbox"][name="topics[]"][value="' . $topicId . '"]');
        $I->seeElement('.practice-filter button[type="submit"]');
        $I->see('Apply', '.practice-filter');
        $I->dontSeeElement('.practice-filter [onchange]');
        $I->dontSeeElement('.practice-filter select[name="topic"]');
    }

    /** What was chosen comes back ticked, so the control and the rows agree. */
    public function theChosenTopicsComeBackTicked(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topics[]=' . $topicId . '&topics[]=none');

        $I->seeElement('.practice-filter input[value="' . $topicId . '"][checked]');
        $I->seeElement('.practice-filter input[value="none"][checked]');
        $I->see('Clear', '.practice-filter');
    }

    /** Clear is a link straight back to the unfiltered list. */
    public function clearReturnsToEveryOrder(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topics[]=' . $topicId);
        $I->click('Clear', '.practice-filter');

        $I->seeCurrentUrlEquals('/practice-orders/store/' . self::STORE_ID);
        $I->see((string) self::ORDER_B, 'tbody');
    }

    /** An empty filtered list says the filter emptied it, not that the store has no recordings. */
    public function aFilterThatMatchesNothingSaysSo(WebTester $I): void
    {
        $empty = $this->seedTopic('ACTIVE', self::TOPIC_B);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topics[]=' . $empty);

        $I->see('No orders match this topic filter', '.a2t-empty-state');
        $I->dontSee('No audio recordings yet', '.a2t-empty-state');
    }

    /** A recording whose order was never mirrored is not practisable, so it is not listed here. */
    public function aRecordingWithNoSourceOrderIsNotListed(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->dontSee((string) self::ORPHAN_ORDER, 'tbody');
        $I->see((string) self::ORDER, 'tbody');
    }

    /** And Order Testing still lists it, because that is where it can be fixed. */
    public function orderTestingStillListsARecordingWithNoSourceOrder(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $I->see((string) self::ORPHAN_ORDER, 'tbody');
        $I->see('No matching source order', '.practice-topic-cell');
    }

    /**
     * The topic is a value in a column of orders, and nothing more.
     *
     * It used to be a button that opened the topic's own details. The details are about the topic, not
     * about the order the reader is looking at, and a name that looks like a link in a table of orders
     * promises something about the order. Topic Management still has the dialog, where a topic is the
     * subject of the page; here the name is set like every other identifying value in the row.
     */
    public function theAssignedTopicNameIsPlainText(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->describeTopic($topicId, 'What this topic is for.');
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->see(self::TOPIC, '.practice-topic-cell .practice-topic__value');

        // Not a control, and not carrying the dialog's payload in its attributes either.
        $I->dontSeeElement('.practice-topic-cell [data-practice-topic-details]');
        $I->dontSeeElement('.practice-topic-cell button');
        $I->dontSeeElement('.practice-topic-cell a');

        // The dialog itself is no longer rendered on this page: nothing here opens it.
        $I->dontSeeElement('#practice-topic-details-dialog');
    }

    /** A topic's description belongs to the topic, and is not published into a table of orders. */
    public function theTopicDescriptionIsNotCarriedIntoTheRow(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->describeTopic($topicId, 'What this topic is for.');
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->dontSee('What this topic is for.');
    }

    /** An active topic must never be labelled as no longer offered. */
    public function anActiveTopicIsNotMarkedUnavailable(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->dontSeeElement('.practice-topic-cell .badge--muted');
        $I->see(self::TOPIC, '.practice-topic-cell .practice-topic__value');
    }

    /** A deleted topic stays on the order that carries it, and says which of the two it is. */
    public function aDeletedTopicStillReadsOnTheOrderThatCarriesIt(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);
        $this->setTopicStatus($topicId, 'ARCHIVED');

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->see(self::TOPIC, '.practice-topic-cell');
        // A badge in the column; the sentence that explains it lives in the details dialog.
        $I->see('Deleted', '.practice-topic-cell .badge--muted');
        $I->see(self::TOPIC, '.practice-topic-cell .practice-topic__value');
        // And it is no longer a choice anywhere.
        $I->dontSeeElement('#practice-bulk-topic option[value="' . $topicId . '"]');
    }

    public function anInactiveTopicSaysInactiveRatherThanJustUnavailable(WebTester $I): void
    {
        $topicId = $this->seedTopic('INACTIVE');
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->see('Inactive', '.practice-topic-cell .badge--muted');
        $I->see(self::TOPIC, '.practice-topic-cell .practice-topic__value');
    }







    /**
     * **A store with more than one page of orders.**
     *
     * The pager partial is only rendered when there is a second page, so a wrong path to it is a 500
     * that no fixture with a handful of rows can see — which is exactly how one stood unnoticed. Both
     * surfaces render the same template, so both are checked.
     */
    public function aStoreWithASecondPageOfOrdersStillRenders(WebTester $I): void
    {
        $this->seedManyOrders(16);

        foreach (['/practice-orders/store/', '/order-testing/store/'] as $prefix) {
            $I->amOnPage($prefix . self::STORE_ID);
            $I->seeResponseCodeIs(200);
            $I->seeElement('nav.pager');

            $I->amOnPage($prefix . self::STORE_ID . '?page=2');
            $I->seeResponseCodeIs(200);
        }
    }


    /** And an active one says nothing about it. */
    public function anActiveStoreShowsNoInactiveWarning(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->dontSee('reports this store as inactive');
    }




    public function aGuestCannotReachPracticeOrders(WebTester $I): void
    {
        $I->resetCookie('KFSESSID');

        $I->amOnPage('/practice-orders');
        $I->seeInCurrentUrl('/login');

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);
        $I->seeInCurrentUrl('/login');
    }

    // ---------------------------------------------------------------- fixtures

    private function seedTopic(string $status = 'ACTIVE', string $name = self::TOPIC): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%practice_topics}}', [
            'name' => $name,
            'name_key' => mb_strtolower($name),
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    private function assignDirectly(int $topicId, int $orderId = self::ORDER): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%practice_order_topics}}', [
            'store_source_id' => self::STORE_ID,
            'source_order_id' => $orderId,
            'topic_id' => $topicId,
            'assigned_by_type' => 'ADMIN',
            'assigned_by_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /**
     * Enough orders to need a second page, each with its own recording.
     *
     * Written straight to the tables rather than through the upload path: this is about how many rows
     * the page is handed, not about how they got there. `cleanup()` removes them with the rest, since
     * it works by store.
     */
    private function seedManyOrders(int $count): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $adminId = (int) (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::USERNAME])->scalar();

        for ($i = 0; $i < $count; $i++) {
            $orderId = 16801000 + $i;
            // Hex, because the job routes constrain `{publicId}` to 32 hex characters and the page
            // builds a URL for every recording that has audio. A fixture id with a letter outside
            // a-f makes the URL generator throw, which reads as a broken page rather than a bad id.
            $publicId = 'ab' . str_pad(dechex($i), 30, '0', STR_PAD_LEFT);

            $this->connection->createCommand()->insert('{{%order58_orders}}', [
                'account_id' => self::STORE_ID,
                'source_order_id' => $orderId,
                'payload_json' => '{}',
                'content_hash' => str_repeat('0', 64),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();

            $this->connection->createCommand()->insert('{{%audio_conversations}}', [
                'public_id' => $publicId,
                'store_source_id' => self::STORE_ID,
                'mode' => 'COMMON',
                'recording_type' => 'MIXED',
                'order_id' => (string) $orderId,
                'generate_ai_audio' => 0,
                'uploaded_by_admin_id' => $adminId,
                'created_at' => $now,
            ])->execute();

            $conversationId = (int) (new Query($this->connection))
                ->select('id')->from('{{%audio_conversations}}')
                ->where(['public_id' => $publicId])->scalar();

            $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
                'public_id' => 'cd' . str_pad(dechex($i), 30, '0', STR_PAD_LEFT),
                'conversation_id' => $conversationId,
                'uploaded_by_admin_id' => $adminId,
                'original_filename' => 'page.wav',
                'retained_audio_path' => 'source.wav',
                'source_role' => 'COMMON',
                'status' => 'COMPLETED',
                'processing_stage' => 'COMPLETED',
                'transcription_provider' => 'WHISPER',
                'duration_seconds' => 1.0,
                'review_count' => 0,
                'created_at' => $now,
            ])->execute();
        }
    }

    private function describeTopic(int $topicId, string $description): void
    {
        $this->connection->createCommand()
            ->update('{{%practice_topics}}', ['description' => $description], ['id' => $topicId])->execute();
    }

    private function setTopicStatus(int $topicId, string $status): void
    {
        $this->connection->createCommand()
            ->update('{{%practice_topics}}', ['status' => $status], ['id' => $topicId])->execute();
    }

    private function assignedTopicId(int $orderId): ?int
    {
        $value = (new Query($this->connection))
            ->select('topic_id')->from('{{%practice_order_topics}}')
            ->where(['store_source_id' => self::STORE_ID, 'source_order_id' => $orderId])
            ->scalar();

        return $value === false || $value === null ? null : (int) $value;
    }

    private function seedStore(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => self::STORE_ID,
            'name' => self::STORE_NAME,
            'active' => 1,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => self::STORE_NAME,
            'slug' => 'kf-practice-' . self::STORE_ID,
            'source_system' => 'order58',
            'source_store_id' => self::STORE_ID,
            'source_name' => self::STORE_NAME,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedOrder(int $orderId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => self::STORE_ID,
            'source_order_id' => $orderId,
            'payload_json' => '{}',
            'content_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedRecording(int $orderId, string $conversationChar, string $jobChar): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $adminId = (int) (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::USERNAME])->scalar();

        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => str_repeat($conversationChar, 32),
            'store_source_id' => self::STORE_ID,
            'mode' => 'COMMON',
            'recording_type' => 'MIXED',
            'order_id' => (string) $orderId,
            'generate_ai_audio' => 0,
            'uploaded_by_admin_id' => $adminId,
            'created_at' => $now,
        ])->execute();

        $conversationRowId = (int) (new Query($this->connection))
            ->select('id')->from('{{%audio_conversations}}')
            ->where(['public_id' => str_repeat($conversationChar, 32)])->scalar();

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => str_repeat($jobChar, 32),
            'conversation_id' => $conversationRowId,
            'uploaded_by_admin_id' => $adminId,
            'original_filename' => 'practice.wav',
            // Held audio, because the practice surfaces list only recordings that can be practised —
            // a conversation row with nothing behind it is not one.
            'retained_audio_path' => 'source.wav',
            'source_role' => 'COMMON',
            'status' => 'NOT_REQUESTED',
            'processing_stage' => 'QUEUED',
            'transcription_provider' => 'WHISPER',
            'duration_seconds' => 12.5,
            'review_count' => 0,
            'created_at' => $now,
        ])->execute();
    }

    /** Scoped to this suite's own store, order, topic and administrator. Never a blanket delete. */
    /** The signed-in administrator's own row id — the realm this suite practises as. */
    private function adminId(): int
    {
        return (int) (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::USERNAME])->scalar();
    }

    /** One finished attempt, so a demo order has something of this reader's to attach to. */
    private function seedAttemptFor(string $realm, int $actorId): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order_testing_attempts}}', [
            'public_id' => str_pad((string) random_int(1, 999999), 32, 'a', STR_PAD_LEFT),
            'store_source_id' => self::STORE_ID,
            'source_order_id' => self::ORDER,
            'initiated_by_type' => $realm,
            'initiated_by_id' => $actorId,
            'status' => 'MATCHED',
            'started_at' => $now,
            'expires_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    /** An imported demo order attributed to that attempt — the thing that earns "Retry". */
    private function seedDemoOrderFor(int $attemptId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order_testing_demo_orders}}', [
            'public_id' => str_pad((string) random_int(1, 999999), 32, 'b', STR_PAD_LEFT),
            'store_source_id' => self::STORE_ID,
            'source_order_id' => self::ORDER,
            'demo_order_id' => 16639001,
            'matched_attempt_id' => $attemptId,
            'source_filename' => 'fixture.json',
            'content_hash' => str_repeat('0', 64),
            'raw_payload' => '{}',
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%practice_order_topics}}', ['store_source_id' => self::STORE_ID])->execute();
        // Both names explicitly, as an IN clause: a LIKE here would be one typo away from deleting
        // somebody else's topics.
        $this->connection->createCommand()
            ->delete('{{%practice_topics}}', ['name' => [self::TOPIC, self::TOPIC_B]])->execute();

        $conversationIds = (new Query($this->connection))
            ->select('id')->from('{{%audio_conversations}}')
            ->where(['store_source_id' => self::STORE_ID])->column();

        if ($conversationIds !== []) {
            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['conversation_id' => $conversationIds])->execute();
            $this->connection->createCommand()
                ->delete('{{%audio_conversations}}', ['id' => $conversationIds])->execute();
        }

        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['source_store_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order_testing_demo_orders}}', ['store_source_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order_testing_attempts}}', ['store_source_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%admin_users}}', ['username' => self::USERNAME])->execute();
    }
}
