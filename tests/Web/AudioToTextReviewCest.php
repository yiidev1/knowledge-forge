<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use App\Auth\Infrastructure\NativePasswordHasher;

use function bin2hex;
use function gmdate;
use function json_decode;
use function json_encode;
use function random_bytes;

use const SORT_ASC;

/**
 * Speaker correction, through the browser.
 *
 * The guarantee under test is the one the whole feature rests on: an administrator can restructure and
 * reword a conversation as much as they like, and the machine's own four columns come back byte for
 * byte afterwards. {@see aCorrectionNeverAltersTheMachineResult} asserts that against the database
 * rather than trusting the page.
 *
 * Fixtures are inserted with explicit statuses and removed by their own public id. Nothing here calls
 * the queue: this database is shared with real use, and a blanket delete would destroy someone's
 * recordings.
 */
final class AudioToTextReviewCest
{
    private const ADMIN = '__kf_a2t_review_admin__';
    private const PASSWORD = 'AudioReviewPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';
    private const TRANSCRIPT = 'Yes. For pikup or delivery?';

    private ConnectionInterface $connection;
    private int $adminId;

    /** @var list<string> */
    private array $created = [];

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->adminId = (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ---------------------------------------------------------------- access

    public function aGuestIsSentToLoginFromTheReviewPage(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/audio-to-text/job/' . $this->seed() . '/review');
        $I->seeInCurrentUrl('/login');
    }

    public function anAgentSessionCannotReachTheReviewPage(WebTester $I): void
    {
        $publicId = $this->seed();

        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/agent/login');
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->dontSee('Correct speakers', 'h1');
    }

    /**
     * Nothing to correct, so the page hands the reader on rather than dead-ending.
     *
     * The conversions list sends every View link here, including queued and failed rows, and the
     * detail page already explains why there is no conversation to correct.
     */
    public function anIncompleteJobIsSentToTheDetailPage(WebTester $I): void
    {
        $publicId = $this->seed(status: 'PROCESSING');

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $publicId);
        $I->see('Processing');
    }

    /** The conversions list's View action opens this page. */
    public function theConversionsListViewActionOpensTheCorrectionPage(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/jobs');
        $I->seeElement('a[href="/audio-to-text/job/' . $publicId . '/review"]');
    }

    public function anUnknownJobHasNoReviewPage(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . bin2hex(random_bytes(16)) . '/review');
        $I->seeResponseCodeIs(404);
    }

    public function theJobPageOffersTheCorrectionPage(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->seeElement('a[href="/audio-to-text/job/' . $publicId . '/review"]');
    }

    // ---------------------------------------------------------------- operations

    public function aTurnCanBeReassigned(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->submitForm('[data-a2t-turn="0"] [data-a2t-fallback] form[action$="/turn/0/move"]', []);

        $I->seeInCurrentUrl('/review');
        $I->see('Turn reassigned to the Agent.');
        Assert::assertSame('AGENT', $this->reviewedTurns($publicId)[0]['role']);
    }

    public function aTurnCanBeSplitAtAChosenPoint(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');

        // The page offers positions in the words rather than asking for a character count.
        $I->see('after “Yes.”');
        $I->submitForm(
            'form[action="/audio-to-text/job/' . $publicId . '/review/turn/0/split"]',
            ['offset' => '4'],
        );

        $I->see('Turn split.');
        $turns = $this->reviewedTurns($publicId);
        Assert::assertCount(3, $turns);
        Assert::assertSame('Yes.', $turns[0]['text']);
        Assert::assertSame('For pikup', $turns[1]['text'], 'A split cuts; it does not reword.');
        // Both halves inherit the parent span and say so, rather than claiming a measured boundary.
        Assert::assertTrue($turns[0]['approx']);
        Assert::assertTrue($turns[1]['approx']);
        Assert::assertSame($turns[0]['start_ms'], $turns[1]['start_ms']);
        Assert::assertSame($turns[0]['end_ms'], $turns[1]['end_ms']);
    }

    public function correctedWordingReplacesOnlyWhatIsShown(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->submitForm(
            'form[action="/audio-to-text/job/' . $publicId . '/review/turn/0/text"]',
            ['text' => 'Yes. For pickup'],
        );

        $I->see('Wording corrected. The original transcript is unchanged.');

        // The corrected wording is what a reader sees...
        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->see('Yes. For pickup');
        $I->see('edited');
        // ...and the machine's own transcript still says what it heard.
        Assert::assertSame(self::TRANSCRIPT, (string) $this->rawColumns($publicId)['transcript']);
    }

    // ---------------------------------------------------------------- move a selection

    /**
     * The move endpoint, exercised the way the page uses it.
     *
     * The browser posts the selected *words*, never an offset — it counts UTF-16 units and the domain
     * counts codepoints, so the two disagree the moment a turn contains an emoji. These tests submit
     * the same fields the script fills in.
     */
    public function aWholeCustomerTurnMovesToTheAgent(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $this->moveText($I, $publicId, 0, 'Yes. For pikup', 'AGENT');

        $I->see('Moved to the Agent.');
        $turns = $this->reviewedTurns($publicId);
        Assert::assertCount(2, $turns, 'A whole-turn move needs no split.');
        Assert::assertSame('AGENT', $turns[0]['role']);
        Assert::assertSame('Yes. For pikup', $turns[0]['text']);
    }

    public function aWholeAgentTurnMovesToTheCustomer(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $this->moveText($I, $publicId, 1, 'or delivery?', 'CUSTOMER');

        $I->see('Moved to the Customer.');
        Assert::assertSame('CUSTOMER', $this->reviewedTurns($publicId)[1]['role']);
    }

    public function aSelectionAtTheStartSplitsOnceAndMovesTheFirstHalf(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $this->moveText($I, $publicId, 0, 'Yes.', 'AGENT');

        $turns = $this->reviewedTurns($publicId);
        Assert::assertCount(3, $turns);
        Assert::assertSame('Yes.', $turns[0]['text']);
        Assert::assertSame('AGENT', $turns[0]['role']);
        Assert::assertSame('For pikup', $turns[1]['text']);
        Assert::assertSame('CUSTOMER', $turns[1]['role']);
        // Both halves inherit the parent span rather than claiming a measured boundary.
        Assert::assertTrue($turns[0]['approx']);
        Assert::assertSame($turns[0]['start_ms'], $turns[1]['start_ms']);
    }

    public function aSelectionAtTheEndSplitsOnceAndMovesTheSecondHalf(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $this->moveText($I, $publicId, 0, 'For pikup', 'AGENT');

        $turns = $this->reviewedTurns($publicId);
        Assert::assertCount(3, $turns);
        Assert::assertSame('Yes.', $turns[0]['text']);
        Assert::assertSame('CUSTOMER', $turns[0]['role']);
        Assert::assertSame('For pikup', $turns[1]['text']);
        Assert::assertSame('AGENT', $turns[1]['role']);
    }

    public function aSelectionInTheMiddleSplitsTwice(WebTester $I): void
    {
        $publicId = $this->seed(customerText: 'Well hello there friend', segmentText: 'Well hello there friend');

        $this->signIn($I);
        $this->moveText($I, $publicId, 0, 'hello there', 'AGENT');

        $turns = $this->reviewedTurns($publicId);
        Assert::assertCount(4, $turns);
        Assert::assertSame('Well', $turns[0]['text']);
        Assert::assertSame('hello there', $turns[1]['text']);
        Assert::assertSame('AGENT', $turns[1]['role']);
        Assert::assertSame('friend', $turns[2]['text']);
    }

    /** Codepoints, not UTF-16 units: an emoji must not shift the cut by one. */
    public function aSelectionAfterAnEmojiIsCutInTheRightPlace(WebTester $I): void
    {
        $publicId = $this->seed(segmentText: 'Great 👍 thanks a lot');

        $this->signIn($I);
        $this->moveText($I, $publicId, 0, 'thanks a lot', 'AGENT');

        $turns = $this->reviewedTurns($publicId);
        Assert::assertSame('Great 👍', $turns[0]['text']);
        Assert::assertSame('thanks a lot', $turns[1]['text']);
        Assert::assertSame('AGENT', $turns[1]['role']);
    }

    /** One operation, one revision — however many splits it took internally. */
    public function aSelectionMoveIsOneAuditedOperation(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $this->moveText($I, $publicId, 0, 'Yes.', 'AGENT');

        Assert::assertSame(1, $this->revisionCount($publicId), 'A composed move is still one revision.');
        Assert::assertSame('MOVE', $this->latestOperation($publicId));
    }

    public function aWholeTurnMoveJoinsAMatchingNeighbour(WebTester $I): void
    {
        // One voice, two roles: moving the second makes them identical, so they become one bubble.
        $publicId = $this->seed(sameSpeaker: true);

        $this->signIn($I);
        $this->moveText($I, $publicId, 1, 'or delivery?', 'CUSTOMER');

        $turns = $this->reviewedTurns($publicId);
        Assert::assertCount(1, $turns, 'Same voice and role: one bubble, not two.');
        Assert::assertSame('Yes. For pikup or delivery?', $turns[0]['text']);
    }

    public function anEmptySelectionIsRefused(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $this->moveText($I, $publicId, 0, '   ', 'AGENT');

        $I->see('Select some words to move first.');
        Assert::assertSame(0, $this->revisionCount($publicId));
    }

    public function aSelectionThatIsNoLongerThereIsRefused(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $this->moveText($I, $publicId, 0, 'words that were never said', 'AGENT');

        $I->see('That selection is no longer part of this turn.');
        Assert::assertSame(0, $this->revisionCount($publicId));
    }

    public function aStaleSelectionMoveIsRefusedAndWritesNothing(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $token = $this->csrfToken($I);

        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['review_count' => 9],
            ['public_id' => $publicId],
        )->execute();

        // Posted straight to the endpoint: the dialog's form has no action of its own, because the
        // script sets it to whichever turn is being moved.
        $I->sendAjaxPostRequest('/audio-to-text/job/' . $publicId . '/review/turn/0/move-text', [
            '_csrf' => $token,
            'expected_review_count' => '0',
            'selection' => 'Yes.',
            'role' => 'AGENT',
            'hint' => '',
        ]);

        $I->see('Somebody else corrected this conversation while you had it open.');
        Assert::assertSame(0, $this->revisionCount($publicId), 'No orphan audit row.');
    }

    // ---------------------------------------------------------------- the page itself

    public function theCorrectionPageRendersAsAChatWithPerMessageControls(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');

        // The conversation page's own layout classes, so the two screens read as one product.
        $I->seeElement('.a2t-chat .a2t-chat__scroll .a2t-thread');
        $I->seeElement('.a2t-turn--left .a2t-bubble');
        $I->seeElement('.a2t-turn--right .a2t-bubble');
        // A pencil for the wording and a six-dot handle for the speaker, plus the two forms the drag
        // confirmations submit. The old arrow button is gone: dragging replaced it.
        $I->seeElement('[data-a2t-turn="0"] [data-a2t-edit]');
        $I->seeElement('[data-a2t-turn="0"] [data-a2t-grip]');
        $I->dontSeeElement('[data-a2t-move]');
        $I->seeElement('[data-a2t-move-dialog] form[data-a2t-move-form]');
        $I->seeElement('[data-a2t-merge-dialog] form[data-a2t-merge-form]');
    }

    /**
     * Exactly one form asks the page to remember where the reader was.
     *
     * The scroll anchor is a turn *index*, and confirmation is the only correction that leaves the
     * turns numbered as they were — everything else may split, merge or reorder them. If this hook
     * spread to another form the restored index would silently name a different message, so the
     * boundary is asserted rather than left to the comment explaining it.
     */
    public function onlyTheConfirmFormCarriesTheScrollAnchorHook(WebTester $I): void
    {
        $publicId = $this->seedThree();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');

        // One correction first, so Discard all corrections is actually on the page — asserting the
        // hook is absent from a form that was never rendered would prove nothing.
        $I->submitForm('[data-a2t-turn="0"] [data-a2t-fallback] form[action$="/turn/0/move"]', []);
        $I->seeElement('form[action$="/review/revert"]');

        $I->seeElement('form[action$="/review/confirm"][data-a2t-confirm-form]');
        $I->dontSeeElement('form[action$="/review/revert"][data-a2t-confirm-form]');
        $I->dontSeeElement('form[action*="/review/turn/"][data-a2t-confirm-form]');
        // One on the page, and it is the confirm form: nothing else may write the anchor.
        $I->seeNumberOfElements('[data-a2t-confirm-form]', 1);
    }

    // ---------------------------------------------------------------- back to the owning store

    /**
     * A job uploaded against a store offers the way back to it.
     *
     * The destination is derived from the job's own conversation, not from how the reader arrived: the
     * page is reached here by direct URL, with no store anywhere in the history, and the link still
     * resolves. That is the whole reason this is not a returnTo parameter.
     */
    public function aStoreOwnedJobOffersTheWayBackToItsStore(WebTester $I): void
    {
        $store = $this->anyMirroredStore();
        if ($store === null) {
            $I->markTestSkipped('No mirrored Order58 store in this database to attach a conversation to.');
        }

        $publicId = $this->seed();
        $this->attachToStore($publicId, $store['sourceId']);

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->seeResponseCodeIsSuccessful();

        // The button, pointing at that store's audio page.
        $I->seeElement('.a2t-chat__actions a[href="/audio-to-text/store/' . $store['sourceId'] . '"]');
        $I->see('Back to ' . $store['name']);
        // And the breadcrumb, which is the second way back.
        $I->seeElement('a[href="/audio-to-text/store/' . $store['sourceId'] . '"]');

        // Added, never substituted: both original actions survive.
        $I->seeElement('a[href="/audio-to-text/job/' . $publicId . '/conversation"]');
        $I->see('Back to conversation');
        $I->seeElement('a[href="/audio-to-text/job/' . $publicId . '"]');
        $I->see('Full conversion details');
    }

    /** Most jobs belong to no store, and their page is exactly what it always was. */
    public function aStorelessJobKeepsTheExistingActionsAndShowsNoStore(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->seeResponseCodeIsSuccessful();

        // No store link anywhere on the page — not in the actions, not in the breadcrumb.
        $I->dontSeeElement('a[href^="/audio-to-text/store/"]');
        $I->dontSee('Back to 888');

        $I->seeElement('a[href="/audio-to-text/job/' . $publicId . '/conversation"]');
        $I->see('Back to conversation');
        $I->seeElement('a[href="/audio-to-text/job/' . $publicId . '"]');
        $I->see('Full conversion details');
    }

    /** Without JavaScript the plain forms are still the whole interface. */
    public function theNoScriptFallbackKeepsEveryOperationReachable(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');

        $I->seeElement('[data-a2t-turn="0"] [data-a2t-fallback] form[action$="/review/turn/0/move"]');
        $I->seeElement('[data-a2t-turn="0"] [data-a2t-fallback] form[action$="/review/turn/0/text"]');
        // Split and join stay reachable, one disclosure down rather than on every message.
        $I->seeElement('[data-a2t-turn="0"] .a2t-turn__advanced');

        $I->submitForm('[data-a2t-turn="0"] [data-a2t-fallback] form[action$="/review/turn/0/move"]', []);
        $I->see('Turn reassigned to the Agent.');
    }

    /**
     * Merge eligibility is published per turn, straight from the domain's own MergeRefusal.
     *
     * The drag reads these attributes to decide what a drop means. If the page ever computed the rule
     * itself, a drop target could offer a merge the service refuses — this is what stops that.
     */
    public function eachTurnPublishesWhetherItCanMergeAndWhyNot(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');

        // A neighbour is all a manual merge needs, whatever the roles or voices are.
        Assert::assertSame('ok', $I->grabAttributeFrom('[data-a2t-turn="0"]', 'data-a2t-merge-next'));
        // No neighbour above the first turn, so the attribute is absent rather than empty.
        $I->dontSeeElement('[data-a2t-turn="0"][data-a2t-merge-prev]');
        // ...and the mirror image at the far end.
        Assert::assertSame('ok', $I->grabAttributeFrom('[data-a2t-turn="1"]', 'data-a2t-merge-prev'));
        $I->dontSeeElement('[data-a2t-turn="1"][data-a2t-merge-next]');
    }

    /** Two turns by one voice in one role publish "ok", and the merge endpoint accepts the drop. */
    public function anAdjacentSameVoiceTurnIsOfferedAndMergesOnConfirm(WebTester $I): void
    {
        // One voice, one role, two turns — exactly what the diarizer produces when it breaks a
        // sentence in half, and the only same-lane drop the domain permits.
        $publicId = $this->seed(
            sameSpeaker: true,
            bothCustomer: true,
            agentText: null,
            customerText: 'Yes. For pikup or delivery?',
        );

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        Assert::assertSame('ok', $I->grabAttributeFrom('[data-a2t-turn="0"]', 'data-a2t-merge-next'));

        // What the drag's merge confirmation submits.
        $review = '/audio-to-text/job/' . $publicId . '/review';
        $I->sendAjaxPostRequest($review . '/turn/0/merge', [
            '_csrf' => $this->csrfToken($I),
            'expected_review_count' => (string) $this->reviewCount($publicId),
            'direction' => 'next',
        ]);

        $turns = $this->reviewedTurns($publicId);
        Assert::assertCount(1, $turns, 'The two turns became one.');
        Assert::assertSame('Yes. For pikup or delivery?', $turns[0]['text']);
        // The span is the two together, and the order they were spoken in is preserved.
        Assert::assertSame(0, $turns[0]['start_ms']);
        Assert::assertSame(3000, $turns[0]['end_ms']);
        Assert::assertSame('MERGE', $this->latestOperation($publicId));
    }

    /**
     * Selecting a message reveals its merge controls, and the edges of the conversation offer only
     * the direction that exists.
     */
    public function theMergeControlsAppearPerTurnAndOmitImpossibleDirections(WebTester $I): void
    {
        $publicId = $this->seed(sameSpeaker: true, bothCustomer: true, agentText: null);

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');

        // Hidden until a message is selected — the thread is not lined with buttons.
        $I->seeElement('[data-a2t-turn="0"] [data-a2t-merge-controls][hidden]');

        // First turn: nothing before it, so no "with previous" at all.
        $I->dontSeeElement('[data-a2t-turn="0"] [data-a2t-merge-with="previous"]');
        $I->seeElement('[data-a2t-turn="0"] [data-a2t-merge-with="next"]');

        // Last turn: the mirror image.
        $I->seeElement('[data-a2t-turn="1"] [data-a2t-merge-with="previous"]');
        $I->dontSeeElement('[data-a2t-turn="1"] [data-a2t-merge-with="next"]');
    }

    /**
     * Adjacency is the whole rule now.
     *
     * The fixture's two turns differ in both role and voice, and both directions are still offered:
     * an administrator pressing "merge" has looked at the turns and decided, and the diarizer's view
     * is the thing they are correcting.
     */
    public function bothDirectionsAreOfferedRegardlessOfRoleOrVoice(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');

        $I->seeElement('[data-a2t-turn="0"] [data-a2t-merge-with="next"]');
        $I->seeElement('[data-a2t-turn="1"] [data-a2t-merge-with="previous"]');
        // Nothing is offered-then-refused any more, so no disabled control and no reason to print.
        $I->dontSeeElement('[data-a2t-turn="0"] [data-a2t-merge-controls] button[disabled]');
        $I->dontSeeElement('.a2t-turn__merge-why');
    }

    /** Different role and different voice, merged by hand through the endpoint the button posts to. */
    public function aDifferentRoleNeighbourMergesOnConfirm(WebTester $I): void
    {
        $publicId = $this->seed();
        $before = $this->rawColumns($publicId);

        $this->signIn($I);
        $review = '/audio-to-text/job/' . $publicId . '/review';
        $I->amOnPage($review);

        $I->sendAjaxPostRequest($review . '/turn/1/merge', [
            '_csrf' => $this->csrfToken($I),
            'expected_review_count' => (string) $this->reviewCount($publicId),
            'direction' => 'previous',
        ]);

        $turns = $this->reviewedTurns($publicId);
        Assert::assertCount(1, $turns);
        Assert::assertSame('Yes. For pikup or delivery?', $turns[0]['text'], 'previous + selected.');
        Assert::assertSame(0, $turns[0]['start_ms']);
        Assert::assertSame(3000, $turns[0]['end_ms']);
        // The joined turn keeps the first one's role and voice.
        Assert::assertSame('CUSTOMER', $turns[0]['role']);
        Assert::assertSame('SPEAKER_00', $turns[0]['speaker']);
        Assert::assertSame('MERGE', $this->latestOperation($publicId));

        // And none of it touched what the machine produced.
        $after = $this->rawColumns($publicId);
        Assert::assertSame($before['transcript'], $after['transcript']);
        Assert::assertSame($before['speaker_segments'], $after['speaker_segments']);
    }

    public function aDifferentRoleNeighbourMergesForwardToo(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $review = '/audio-to-text/job/' . $publicId . '/review';
        $I->amOnPage($review);

        $I->sendAjaxPostRequest($review . '/turn/0/merge', [
            '_csrf' => $this->csrfToken($I),
            'expected_review_count' => (string) $this->reviewCount($publicId),
            'direction' => 'next',
        ]);

        $turns = $this->reviewedTurns($publicId);
        Assert::assertCount(1, $turns);
        Assert::assertSame('Yes. For pikup or delivery?', $turns[0]['text'], 'selected + next.');
    }

    /**
     * Whisper's `>>` speaker-change markers are removed on the way to the screen, and only there.
     */
    public function speakerChangeMarkersAreHiddenButNeverErasedFromTheRecord(WebTester $I): void
    {
        $publicId = $this->seed(segmentText: '>> Okay, hold on. >>');

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');

        $I->see('Okay, hold on.', '[data-a2t-turn="0"] [data-a2t-text]');
        $I->dontSee('>>', '[data-a2t-turn="0"] [data-a2t-text]');

        // The stored segments still carry them: they are the only clue a turn holds two speakers.
        $raw = (string) $this->rawColumns($publicId)['speaker_segments'];
        Assert::assertStringContainsString('>>', $raw, 'The machine record is untouched.');
    }

    /** Merging into the previous turn: previous text first, then the selected one. */
    public function mergingWithThePreviousTurnKeepsSpokenOrder(WebTester $I): void
    {
        $publicId = $this->seed(sameSpeaker: true, bothCustomer: true, agentText: null);

        $this->signIn($I);
        $review = '/audio-to-text/job/' . $publicId . '/review';
        $I->amOnPage($review);

        // Turn 1 merged into turn 0 — the direction the "With previous" button sends.
        $I->sendAjaxPostRequest($review . '/turn/1/merge', [
            '_csrf' => $this->csrfToken($I),
            'expected_review_count' => (string) $this->reviewCount($publicId),
            'direction' => 'previous',
        ]);

        $turns = $this->reviewedTurns($publicId);
        Assert::assertCount(1, $turns, 'The selected turn is gone as a separate message.');
        Assert::assertSame('Yes. For pikup or delivery?', $turns[0]['text'], 'previous + selected.');
        // The span covers both turns: min of the starts, max of the ends.
        Assert::assertSame(0, $turns[0]['start_ms']);
        Assert::assertSame(3000, $turns[0]['end_ms']);
        Assert::assertSame('MERGE', $this->latestOperation($publicId));
    }

    /**
     * Adjacency is structural, not asserted by the request.
     *
     * The endpoint takes a turn index and a direction; the server derives the neighbour itself. There
     * is no field naming a target, so a crafted post cannot pair two turns that are not side by side —
     * and asking for a neighbour that does not exist is refused rather than guessed at.
     */
    public function aMergeBeyondTheEdgeOfTheConversationIsRefused(WebTester $I): void
    {
        $publicId = $this->seed(sameSpeaker: true, bothCustomer: true, agentText: null);

        $this->signIn($I);
        $review = '/audio-to-text/job/' . $publicId . '/review';
        $I->amOnPage($review);

        // Nothing precedes turn 0, and nothing follows the last turn. PhpBrowser follows the
        // redirect, so the flash is on the page the client is already looking at.
        foreach ([['0', 'previous'], ['1', 'next']] as [$index, $direction]) {
            $I->amOnPage($review);
            $I->sendAjaxPostRequest($review . '/turn/' . $index . '/merge', [
                '_csrf' => $this->csrfToken($I),
                'expected_review_count' => (string) $this->reviewCount($publicId),
                'direction' => $direction,
            ]);
            $I->see('There is no turn on that side to merge with.');
        }

        Assert::assertNull($this->rawColumns($publicId)['reviewed_segments'], 'Nothing was written.');
        Assert::assertSame(0, $this->revisionCount($publicId));
    }

    public function aMergeWithoutACsrfTokenChangesNothing(WebTester $I): void
    {
        $publicId = $this->seed(sameSpeaker: true, bothCustomer: true, agentText: null);

        $this->signIn($I);
        $review = '/audio-to-text/job/' . $publicId . '/review';
        $I->amOnPage($review);

        $I->sendAjaxPostRequest($review . '/turn/1/merge', [
            'expected_review_count' => (string) $this->reviewCount($publicId),
            'direction' => 'previous',
        ]);

        Assert::assertNull($this->rawColumns($publicId)['reviewed_segments'], 'CSRF is still enforced.');
        Assert::assertSame(0, $this->revisionCount($publicId));
    }

    public function aStaleMergeIsRefusedAndWritesNothing(WebTester $I): void
    {
        $publicId = $this->seed(sameSpeaker: true, bothCustomer: true, agentText: null);

        $this->signIn($I);
        $review = '/audio-to-text/job/' . $publicId . '/review';
        $I->amOnPage($review);
        $token = $this->csrfToken($I);

        // Somebody else corrects the conversation while this page is open.
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['review_count' => 5],
            ['public_id' => $publicId],
        )->execute();

        $I->sendAjaxPostRequest($review . '/turn/1/merge', [
            '_csrf' => $token,
            'expected_review_count' => '0',
            'direction' => 'previous',
        ]);

        $I->see('Somebody else corrected this conversation while you had it open.');
        Assert::assertNull($this->rawColumns($publicId)['reviewed_segments']);
        Assert::assertSame(0, $this->revisionCount($publicId), 'No orphan audit row.');
    }

    /** Whatever a merge does to the reviewed layer, the machine's own columns are untouched. */
    public function aMergeNeverAltersTheMachineResult(WebTester $I): void
    {
        $publicId = $this->seed(sameSpeaker: true, bothCustomer: true, agentText: null);
        $before = $this->rawColumns($publicId);

        $this->signIn($I);
        $review = '/audio-to-text/job/' . $publicId . '/review';
        $I->amOnPage($review);
        $I->sendAjaxPostRequest($review . '/turn/1/merge', [
            '_csrf' => $this->csrfToken($I),
            'expected_review_count' => (string) $this->reviewCount($publicId),
            'direction' => 'previous',
        ]);

        $after = $this->rawColumns($publicId);
        Assert::assertSame($before['transcript'], $after['transcript']);
        Assert::assertSame($before['speaker_segments'], $after['speaker_segments']);
        Assert::assertSame($before['agent_text'], $after['agent_text']);
        Assert::assertSame($before['customer_text'], $after['customer_text']);
        Assert::assertNotNull($after['reviewed_segments'], 'The correction lives in the reviewed layer.');
    }

    /**
     * A highlighted range moves only those words; the source turn stays with the rest.
     *
     * The payload is the one Chrome actually submits — offsets in codepoints plus the selected text as
     * a checksum, never a substring to search for.
     */
    public function aHighlightedRangeMovesIntoThePreviousTurnAndLeavesTheRest(WebTester $I): void
    {
        $publicId = $this->seedThree();
        $before = $this->rawColumns($publicId);

        $this->signIn($I);
        $review = '/audio-to-text/job/' . $publicId . '/review';
        $I->amOnPage($review);

        // "fried rice," begins at codepoint 23 of "So lo mein with shrimp fried rice,".
        $I->sendAjaxPostRequest($review . '/turn/1/merge', [
            '_csrf' => $this->csrfToken($I),
            'expected_review_count' => (string) $this->reviewCount($publicId),
            'direction' => 'previous',
            'selection_start' => '23',
            'selection_end' => '34',
            'selection_text' => 'fried rice,',
        ]);

        $turns = $this->reviewedTurns($publicId);
        Assert::assertCount(3, $turns, 'The source turn still holds words, so it stays.');
        Assert::assertSame('Hello there. fried rice,', $turns[0]['text']);
        Assert::assertSame('So lo mein with shrimp', $turns[1]['text']);
        Assert::assertSame('Anything else?', $turns[2]['text'], 'The far side is untouched.');

        // Neither turn claims a millisecond it does not have.
        Assert::assertSame(0, $turns[0]['start_ms']);
        Assert::assertSame(1000, $turns[0]['end_ms']);
        Assert::assertTrue($turns[0]['approx']);
        Assert::assertTrue($turns[1]['approx']);

        Assert::assertSame('MERGE', $this->latestOperation($publicId));
        Assert::assertSame(1, $this->revisionCount($publicId));

        $after = $this->rawColumns($publicId);
        Assert::assertSame($before['transcript'], $after['transcript']);
        Assert::assertSame($before['speaker_segments'], $after['speaker_segments']);
        Assert::assertSame($before['agent_text'], $after['agent_text']);
        Assert::assertSame($before['customer_text'], $after['customer_text']);
    }

    public function aHighlightedRangeMovesIntoTheNextTurnInFront(WebTester $I): void
    {
        $publicId = $this->seedThree();

        $this->signIn($I);
        $review = '/audio-to-text/job/' . $publicId . '/review';
        $I->amOnPage($review);

        $I->sendAjaxPostRequest($review . '/turn/1/merge', [
            '_csrf' => $this->csrfToken($I),
            'expected_review_count' => (string) $this->reviewCount($publicId),
            'direction' => 'next',
            'selection_start' => '23',
            'selection_end' => '34',
            'selection_text' => 'fried rice,',
        ]);

        $turns = $this->reviewedTurns($publicId);
        Assert::assertCount(3, $turns);
        Assert::assertSame('Hello there.', $turns[0]['text']);
        Assert::assertSame('So lo mein with shrimp', $turns[1]['text']);
        Assert::assertSame('fried rice, Anything else?', $turns[2]['text']);
    }

    /** Selecting every word leaves nothing behind, so the source turn goes with it. */
    public function selectingTheWholeTurnStillRemovesIt(WebTester $I): void
    {
        $publicId = $this->seedThree();

        $this->signIn($I);
        $review = '/audio-to-text/job/' . $publicId . '/review';
        $I->amOnPage($review);

        $I->sendAjaxPostRequest($review . '/turn/1/merge', [
            '_csrf' => $this->csrfToken($I),
            'expected_review_count' => (string) $this->reviewCount($publicId),
            'direction' => 'previous',
            'selection_start' => '0',
            'selection_end' => '34',
            'selection_text' => 'So lo mein with shrimp fried rice,',
        ]);

        $turns = $this->reviewedTurns($publicId);
        Assert::assertCount(2, $turns, 'Nothing left behind, so the card is gone.');
        Assert::assertSame('Hello there. So lo mein with shrimp fried rice,', $turns[0]['text']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function craftedRanges(): iterable
    {
        yield 'negative start' => ['-1', '5', 'So lo'];
        yield 'end before start' => ['10', '4', 'x'];
        yield 'past the end' => ['0', '999', 'x'];
        yield 'empty range' => ['4', '4', ''];
        yield 'text does not match' => ['0', '5', 'never said'];
        yield 'not a number' => ['abc', 'def', 'So lo'];
    }

    /**
     * @dataProvider craftedRanges
     */
    public function aCraftedRangeIsRefusedAndWritesNothing(
        WebTester $I,
        \Codeception\Example $example,
    ): void {
        $publicId = $this->seedThree();

        $this->signIn($I);
        $review = '/audio-to-text/job/' . $publicId . '/review';
        $I->amOnPage($review);

        $I->sendAjaxPostRequest($review . '/turn/1/merge', [
            '_csrf' => $this->csrfToken($I),
            'expected_review_count' => (string) $this->reviewCount($publicId),
            'direction' => 'previous',
            'selection_start' => $example[0],
            'selection_end' => $example[1],
            'selection_text' => $example[2],
        ]);

        Assert::assertNull($this->rawColumns($publicId)['reviewed_segments'], 'Nothing was written.');
        Assert::assertSame(0, $this->revisionCount($publicId), 'And no audit row.');
    }

    public function aStaleRangeMoveIsRefused(WebTester $I): void
    {
        $publicId = $this->seedThree();

        $this->signIn($I);
        $review = '/audio-to-text/job/' . $publicId . '/review';
        $I->amOnPage($review);
        $token = $this->csrfToken($I);

        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['review_count' => 4],
            ['public_id' => $publicId],
        )->execute();

        $I->sendAjaxPostRequest($review . '/turn/1/merge', [
            '_csrf' => $token,
            'expected_review_count' => '0',
            'direction' => 'previous',
            'selection_start' => '23',
            'selection_end' => '34',
            'selection_text' => 'fried rice,',
        ]);

        $I->see('Somebody else corrected this conversation while you had it open.');
        Assert::assertNull($this->rawColumns($publicId)['reviewed_segments']);
        Assert::assertSame(0, $this->revisionCount($publicId));
    }

    /** Three turns: a previous, a source with words to split off, and a next. */
    private function seedThree(): string
    {
        $publicId = bin2hex(random_bytes(16));
        $this->created[] = $publicId;

        $rows = [
            ['start_ms' => 0, 'end_ms' => 1000, 'speaker' => 'SPEAKER_00',
                'role' => 'AGENT', 'text' => 'Hello there.', 'confidence' => 0.9],
            ['start_ms' => 2000, 'end_ms' => 4000, 'speaker' => 'SPEAKER_01',
                'role' => 'CUSTOMER', 'text' => 'So lo mein with shrimp fried rice,', 'confidence' => 0.9],
            ['start_ms' => 5000, 'end_ms' => 6000, 'speaker' => 'SPEAKER_00',
                'role' => 'AGENT', 'text' => 'Anything else?', 'confidence' => 0.9],
        ];

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => $publicId,
            'uploaded_by_admin_id' => $this->adminId,
            'status' => 'COMPLETED',
            'processing_stage' => 'COMPLETED',
            'original_filename' => 'range-fixture.wav',
            'transcript' => 'Hello there. So lo mein with shrimp fried rice, Anything else?',
            'agent_text' => 'Hello there. Anything else?',
            'customer_text' => 'So lo mein with shrimp fried rice,',
            'speaker_segments' => (string) json_encode($rows),
            'speaker_separation_status' => 'COMPLETED',
            'speaker_role_confidence' => 0.9,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();

        return $publicId;
    }

    // ---------------------------------------------------------------- merge refusal

    /**
     * The recommendation, pinned.
     *
     * Once two turns share a role the thing that separates them — the voice the diarizer heard — is no
     * longer visible on screen. A control that simply vanished would read as a bug, so it stays and
     * says why.
     */
    /**
     * No merge is refused any more, so nothing is offered-then-disabled.
     *
     * This test used to pin the opposite. It is kept, inverted, because the absence of a refusal is
     * now the behaviour worth guarding: a stray re-introduction of the role or voice check would show
     * up here rather than only in the domain.
     */
    public function noMergeIsOfferedAndThenRefused(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');

        $I->dontSee('That turn is assigned to the other role.');
        $I->dontSee('The system heard two different voices here');
        $I->dontSeeElement('.a2t-turn__refused button[disabled]');

        // Even after a move makes the two turns share a role but not a voice.
        $I->submitForm('[data-a2t-turn="0"] [data-a2t-fallback] form[action$="/turn/0/move"]', []);
        $I->dontSee('The system heard two different voices here');
        $I->dontSeeElement('.a2t-turn__refused button[disabled]');
    }

    /** Halves of one split share a voice, so undoing a split is always available. */
    public function splitHalvesCanBeRejoined(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->submitForm(
            'form[action="/audio-to-text/job/' . $publicId . '/review/turn/0/split"]',
            ['offset' => '4'],
        );

        $I->submitForm('[data-a2t-turn="0"] form[action$="/turn/0/merge"]', []);
        $I->see('Turns joined.');
        Assert::assertSame('Yes. For pikup', $this->reviewedTurns($publicId)[0]['text']);
    }

    // ---------------------------------------------------------------- NEEDS_REVIEW round trip

    /**
     * The amendment, end to end.
     *
     * Neutral before confirmation, labelled after it, neutral again after a revert — and the split
     * cards follow the labels rather than the machine's own verdict, which never changes.
     */
    public function aNeedsReviewCallIsNeutralUntilConfirmedAndNeutralAgainAfterRevert(WebTester $I): void
    {
        $publicId = $this->seed(separation: 'NEEDS_REVIEW', agentText: null, customerText: null);

        $this->signIn($I);

        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->see('Speaker 1');
        $I->dontSeeElement('.a2t-split');

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->see('The system could not tell which speaker is the agent.');

        // Before confirmation every turn is provisional, so none may be tinted as the agent's — the
        // role attribute carries the mapper's guess either way, and the CSS keys off this class.
        $I->seeElement('.a2t-turn--unconfirmed[data-a2t-role="AGENT"]');
        $I->dontSeeElement('[data-a2t-role="AGENT"]:not(.a2t-turn--unconfirmed)');

        $I->click('Confirm speaker roles');
        $I->see('Speaker roles confirmed.');

        // After it, the agent's turns are published and the tint applies; the customer's turns keep
        // carrying their own role and are never matched by the agent rule.
        $I->seeElement('[data-a2t-role="AGENT"]:not(.a2t-turn--unconfirmed)');
        $I->seeElement('[data-a2t-role="CUSTOMER"]:not(.a2t-turn--unconfirmed)');
        $I->dontSeeElement('.a2t-turn--unconfirmed');

        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->dontSee('Speaker 1');
        $I->see('Roles confirmed by an administrator');
        $I->seeElement('.a2t-split');
        // The machine's own verdict is untouched — publication came from the person, not a rewrite.
        Assert::assertSame('NEEDS_REVIEW', (string) $this->rawColumns($publicId)['speaker_separation_status']);

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->click('Discard all corrections');
        $I->see('Corrections discarded.');

        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->see('Speaker 1');
        $I->dontSeeElement('.a2t-split');
    }

    // ---------------------------------------------------------------- the empty-role guard

    public function aOneSidedConversationCannotBeConfirmed(WebTester $I): void
    {
        $publicId = $this->seed(separation: 'NEEDS_REVIEW', agentText: null, customerText: null);

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');

        // Everything on one side: there is no split left to confirm.
        $I->submitForm('[data-a2t-turn="0"] [data-a2t-fallback] form[action$="/turn/0/move"]', []);
        $I->see('Roles cannot be confirmed until at least one non-empty turn is assigned to both Agent and Customer.');
        $I->dontSeeElement('form[action="/audio-to-text/job/' . $publicId . '/review/confirm"]');
    }

    /** And the page is not the gate: posting directly is refused the same way. */
    public function theConfirmationGuardIsEnforcedOnTheServer(WebTester $I): void
    {
        $publicId = $this->seed(separation: 'NEEDS_REVIEW', agentText: null, customerText: null);

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->submitForm('[data-a2t-turn="0"] [data-a2t-fallback] form[action$="/turn/0/move"]', []);

        $I->sendAjaxPostRequest(
            '/audio-to-text/job/' . $publicId . '/review/confirm',
            ['expected_review_count' => '1', '_csrf' => $this->csrfToken($I)],
        );

        Assert::assertNull($this->rawColumns($publicId)['roles_confirmed_at']);
    }

    // ---------------------------------------------------------------- conflict

    public function aStaleVersionIsRefusedWithAnExplanation(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');

        // Somebody else corrects the conversation while this page is open.
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['review_count' => 7],
            ['public_id' => $publicId],
        )->execute();

        $I->submitForm('[data-a2t-turn="0"] [data-a2t-fallback] form[action$="/turn/0/move"]', []);

        $I->see('Somebody else corrected this conversation while you had it open.');
        $I->see('Your change was not applied.');
        Assert::assertSame(0, $this->revisionCount($publicId), 'A refused save leaves no audit row.');
    }

    // ---------------------------------------------------------------- the guarantee

    public function aCorrectionNeverAltersTheMachineResult(WebTester $I): void
    {
        $publicId = $this->seed();
        $before = $this->rawColumns($publicId);

        $this->signIn($I);
        $review = '/audio-to-text/job/' . $publicId . '/review';

        // Every operation type, each one valid. A turn offers exactly one move form — the role it does
        // not already have — so a turn-scoped selector names one action without ambiguity.
        $I->amOnPage($review);
        $I->submitForm('form[action="' . $review . '/turn/0/split"]', ['offset' => '4']);
        $I->amOnPage($review);
        $I->submitForm('[data-a2t-turn="0"] form[action$="/turn/0/merge"]', []);
        $I->amOnPage($review);
        $I->submitForm('form[action="' . $review . '/turn/0/text"]', ['text' => 'Yes. For pickup']);
        $I->amOnPage($review);
        $I->submitForm('form[action="' . $review . '/turn/0/move"]', []);
        // Back to a turn on each side, which confirmation requires.
        $I->amOnPage($review);
        $I->submitForm('form[action="' . $review . '/turn/1/move"]', []);
        $I->amOnPage($review);
        $I->click('Confirm speaker roles');

        $after = $this->rawColumns($publicId);

        Assert::assertSame($before['transcript'], $after['transcript']);
        Assert::assertSame($before['speaker_segments'], $after['speaker_segments']);
        Assert::assertSame($before['agent_text'], $after['agent_text']);
        Assert::assertSame($before['customer_text'], $after['customer_text']);
        Assert::assertStringContainsString('pikup', (string) $after['transcript'], 'The mishearing survives.');
    }

    // ---------------------------------------------------------------- helpers

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    /** Submits the move form the way the script fills it in. */
    private function moveText(WebTester $I, string $publicId, int $index, string $selection, string $role): void
    {
        $review = '/audio-to-text/job/' . $publicId . '/review';

        $I->amOnPage($review);
        $I->sendAjaxPostRequest($review . '/turn/' . $index . '/move-text', [
            '_csrf' => $this->csrfToken($I),
            'expected_review_count' => (string) $this->reviewCount($publicId),
            'selection' => $selection,
            'role' => $role,
            'hint' => '',
        ]);
        // PhpBrowser follows the redirect, so the client is already back on the review page with the
        // flash rendered. Navigating again would consume it and assert against a clean page.
    }

    private function reviewCount(string $publicId): int
    {
        return (int) (new Query($this->connection))
            ->select('review_count')
            ->from('{{%audio_transcription_jobs}}')
            ->where(['public_id' => $publicId])
            ->scalar();
    }

    private function latestOperation(string $publicId): string
    {
        $jobId = (new Query($this->connection))
            ->select('id')
            ->from('{{%audio_transcription_jobs}}')
            ->where(['public_id' => $publicId])
            ->scalar();

        return (string) (new Query($this->connection))
            ->select('operation')
            ->from('{{%audio_segment_revisions}}')
            ->where(['job_id' => $jobId])
            ->orderBy(['id' => SORT_DESC])
            ->limit(1)
            ->scalar();
    }

    private function csrfToken(WebTester $I): string
    {
        return (string) $I->grabAttributeFrom('input[name="_csrf"]', 'value');
    }

    /**
     * @return array<string, mixed>
     */
    private function rawColumns(string $publicId): array
    {
        /** @var array<string, mixed> $row */
        $row = (array) (new Query($this->connection))
            ->select([
                'transcript',
                'speaker_segments',
                'agent_text',
                'customer_text',
                'speaker_separation_status',
                'reviewed_segments',
                'roles_confirmed_at',
            ])
            ->from('{{%audio_transcription_jobs}}')
            ->where(['public_id' => $publicId])
            ->one();

        return $row;
    }

    private function reviewedSegments(string $publicId): string
    {
        return (string) $this->rawColumns($publicId)['reviewed_segments'];
    }

    /**
     * Decoded, because MySQL reformats a JSON column — key order and spacing are its business, not
     * ours, and asserting on the raw string would pin a detail of the storage engine.
     *
     * @return list<array<string, mixed>>
     */
    private function reviewedTurns(string $publicId): array
    {
        /** @var list<array<string, mixed>> $decoded */
        $decoded = (array) json_decode($this->reviewedSegments($publicId), true);

        return $decoded;
    }

    private function revisionCount(string $publicId): int
    {
        $jobId = (new Query($this->connection))
            ->select('id')
            ->from('{{%audio_transcription_jobs}}')
            ->where(['public_id' => $publicId])
            ->scalar();

        return (int) (new Query($this->connection))
            ->from('{{%audio_segment_revisions}}')
            ->where(['job_id' => $jobId])
            ->count();
    }

    private function seed(
        string $status = 'COMPLETED',
        string $separation = 'COMPLETED',
        ?string $agentText = 'or delivery?',
        ?string $customerText = 'Yes. For pikup',
        ?string $segmentText = null,
        bool $sameSpeaker = false,
        bool $bothCustomer = false,
    ): string {
        $publicId = bin2hex(random_bytes(16));
        $this->created[] = $publicId;

        $segments = [
            [
                'start_ms' => 0, 'end_ms' => 2000, 'speaker' => 'SPEAKER_00',
                'role' => 'CUSTOMER', 'text' => $segmentText ?? 'Yes. For pikup', 'confidence' => 0.9,
            ],
            [
                'start_ms' => 2100, 'end_ms' => 3000, 'speaker' => $sameSpeaker ? 'SPEAKER_00' : 'SPEAKER_01',
                'role' => $bothCustomer ? 'CUSTOMER' : 'AGENT', 'text' => 'or delivery?', 'confidence' => 0.9,
            ],
        ];

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => $publicId,
            'uploaded_by_admin_id' => $this->adminId,
            'status' => $status,
            'processing_stage' => $status === 'COMPLETED' ? 'COMPLETED' : 'TRANSCRIBING',
            'original_filename' => 'review-web-fixture.wav',
            'transcript' => self::TRANSCRIPT,
            'agent_text' => $agentText,
            'customer_text' => $customerText,
            'speaker_segments' => (string) json_encode($segments),
            'speaker_separation_status' => $separation,
            'speaker_role_confidence' => $separation === 'COMPLETED' ? 0.9 : 0.08,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'completed_at' => $status === 'COMPLETED' ? gmdate('Y-m-d H:i:s') : null,
        ])->execute();

        return $publicId;
    }

    /**
     * An existing mirrored store, or null when this database has none.
     *
     * Read rather than seeded: `order58_stores` and `knowledge_bases` are the Order58 mirror, shared
     * with real use, and inventing a row there to satisfy a navigation test would be writing into
     * another module's data. The lookup mirrors DbAudioStoreLookup's own join, so a store this finds is
     * one the page can resolve.
     *
     * @return array{sourceId: int, name: string}|null
     */
    private function anyMirroredStore(): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = (new Query($this->connection))
            ->select(['source_id' => 's.source_id', 'name' => 'kb.name'])
            ->from(['s' => '{{%order58_stores}}'])
            ->innerJoin(['kb' => '{{%knowledge_bases}}'], 'kb.source_store_id = s.source_id')
            ->where(['kb.source_system' => 'order58'])
            ->orderBy(['s.source_id' => SORT_ASC])
            ->limit(1)
            ->one();

        return $row === null ? null : ['sourceId' => (int) $row['source_id'], 'name' => (string) $row['name']];
    }

    /** Attach an existing job to a conversation owned by the given store. */
    private function attachToStore(string $publicId, int $storeSourceId): void
    {
        $conversationPublicId = bin2hex(random_bytes(16));

        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => $conversationPublicId,
            'store_source_id' => $storeSourceId,
            'mode' => 'COMMON',
            'uploaded_by_admin_id' => $this->adminId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();

        $conversationId = (new Query($this->connection))
            ->select('id')
            ->from('{{%audio_conversations}}')
            ->where(['public_id' => $conversationPublicId])
            ->scalar();

        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['conversation_id' => $conversationId, 'source_role' => 'COMMON'],
            ['public_id' => $publicId],
        )->execute();
    }

    /** Scoped to this suite's own administrator, never a blanket delete. */
    private function cleanup(): void
    {
        $adminIds = (new Query($this->connection))
            ->select('id')
            ->from('{{%admin_users}}')
            ->where(['username' => self::ADMIN])
            ->column();

        if ($adminIds !== []) {
            // Jobs before administrators: the uploader foreign key is RESTRICT.
            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['uploaded_by_admin_id' => $adminIds])
                ->execute();

            // Conversations after the jobs that point at them, and before the administrator they
            // point at — both foreign keys are RESTRICT.
            $this->connection->createCommand()
                ->delete('{{%audio_conversations}}', ['uploaded_by_admin_id' => $adminIds])
                ->execute();
        }

        IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => self::ADMIN]);

        $this->created = [];
    }
}
