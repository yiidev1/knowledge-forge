<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\LegacySeparateAudioUpload;
use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_keys;
use function codecept_data_dir;
use function file_put_contents;
use function gmdate;
use function json_decode;
use function json_encode;
use function is_file;
use function pack;
use function str_repeat;
use function strlen;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const SORT_DESC;

/**
 * Store-wise audio, end to end against the real served application.
 *
 * **No test here starts ffmpeg or whisper.** Uploads reach QUEUED and stop, because no worker runs
 * during this suite — which is the assertion, not a limitation: the HTTP request validates and queues
 * and does nothing else.
 *
 * The two facts this file exists to pin are the ones a template could quietly get wrong:
 *
 * 1. **A separate upload is one conversion.** Two jobs in the queue, one row in the store's history,
 *    one entry in its count. Counting a pair twice would be wrong on every store-facing screen.
 * 2. **The store comes from the route.** A posted `store_id` is never read, so reaching one store's
 *    page can never write a conversation onto another store's history.
 */
final class AudioToTextStoreCest
{
    use LegacySeparateAudioUpload;

    private const ADMIN = '__kf_a2t_store_admin__';
    private const PASSWORD = 'AudioStorePassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    /** Two stores, so "only this store's history" is something a test can actually observe. */
    private const STORE_A = 987654322;
    private const STORE_B = 987654323;
    private const STORE_A_NAME = '__KF Audio Store Alpha__';
    private const STORE_B_NAME = '__KF Audio Store Beta__';

    /** Source-inactive, so the picker must refuse to send new recordings to it. */
    private const STORE_C = 987654324;
    private const STORE_C_NAME = '__KF Audio Store Gamma__';

    private const PICKER_URL = '/admin/order58/store-audio';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->createStore(self::STORE_A, self::STORE_A_NAME);
        $this->createStore(self::STORE_B, self::STORE_B_NAME);
        $this->createStore(self::STORE_C, self::STORE_C_NAME, active: false);
        $this->writeFixtures();
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------------------ the picker

    public function aGuestIsSentToLoginFromThePicker(WebTester $I): void
    {
        $I->amOnPage(self::PICKER_URL);
        $I->seeCurrentUrlEquals('/login');
    }

    public function thePickerListsStoresAndLinksToTheirAudioPage(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL);
        $I->seeResponseCodeIs(200);
        $I->see(self::STORE_A_NAME);
        $I->see('Manage audio');
        $I->seeElement('a.store-card[href="' . $this->storeUrl(self::STORE_A) . '"]');
    }

    /**
     * Knowledge is not a gate here, unlike Store chat.
     *
     * A store with no documents at all can still have a recording transcribed, so it is a live link.
     */
    public function aStoreWithNoKnowledgeIsStillAValidAudioDestination(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL . '?q=' . urlencode(self::STORE_A_NAME));
        $I->dontSee('Chat unavailable');
        $I->seeElement('a.store-card[href="' . $this->storeUrl(self::STORE_A) . '"]');
    }

    /** Source-active *is* a gate: a store Order58 reports as inactive takes no new recordings. */
    public function anInactiveStoreCardIsDisabled(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL . '?q=' . urlencode(self::STORE_C_NAME));
        $I->see(self::STORE_C_NAME);
        $I->see('Audio unavailable — source inactive');
        $I->dontSeeElement('a.store-card[href="' . $this->storeUrl(self::STORE_C) . '"]');
        $I->seeElement('.store-card[aria-disabled="true"]');
    }

    /**
     * The disabled card is a hint; this is the rule.
     *
     * Someone with the URL — a stale tab, a bookmark — must not be able to queue work for a store
     * that is no longer live, and must be told why rather than watching nothing happen.
     */
    public function anInactiveStoreRefusesAnUploadServerSide(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage($this->storeUrl(self::STORE_C));
        $I->seeResponseCodeIs(200);
        $I->see('no new recordings can be uploaded for it');
        // The form is not merely disabled in the markup — it is not rendered at all, and neither is
        // the dialog that would hold it or the button that would open it.
        $I->dontSeeElement($this->uploadForm());
        $I->dontSeeElement('[data-a2t-open]');
    }

    /**
     * The stale tab, which is the case the server-side guard actually exists for.
     *
     * The page is opened while the store is live, the store goes inactive, and the form is submitted
     * from the page already on screen. That request carries a valid session and a valid CSRF token —
     * everything a legitimate upload has — so nothing but the guard itself can refuse it. Posting a
     * hand-made request instead would be refused by the CSRF middleware and the test would pass
     * without ever reaching the rule it claims to check.
     */
    public function aFormOpenedBeforeAStoreWentInactiveStillUploadsNothing(WebTester $I): void
    {
        $this->signIn($I);
        $this->setStoreActive(self::STORE_C, true);

        $I->amOnPage($this->storeUrl(self::STORE_C));
        $I->seeElement($this->uploadForm());
        $I->attachFile('#a2t-audio', 'kf_store_valid.wav');

        // Order58 deactivates the store while the administrator is still looking at the form.
        $this->setStoreActive(self::STORE_C, false);

        $I->submitForm($this->uploadForm(), []);

        $I->see('no new recordings can be uploaded for it');
        Assert::assertSame([], $this->conversationsFor(self::STORE_C));
        Assert::assertSame(0, $this->jobCountFor(self::STORE_C));
    }

    /**
     * Its page stays readable, because the history has to remain reachable — the Store column on the
     * global conversions list links straight here.
     */
    public function anInactiveStoreStillShowsItsHistory(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage($this->storeUrl(self::STORE_C));
        $I->seeResponseCodeIs(200);
        $I->see(self::STORE_C_NAME);
        $I->see("This store's conversions");
    }

    // ------------------------------------------------------------------ counts and the audio filter

    /**
     * The card counts **conversions**, not jobs.
     *
     * A separate Customer + Agent upload is two rows in the queue and one conversion here, and this
     * is the number an administrator made — counting the jobs would say 2 for one upload.
     */
    public function aCardCountsConversionsNotJobs(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL . '?q=' . urlencode(self::STORE_A_NAME));
        $I->see('🎙 0');

        $this->uploadSeparate($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL . '?q=' . urlencode(self::STORE_A_NAME));
        $I->see('🎙 1');
        Assert::assertSame(2, $this->jobCountFor(self::STORE_A), 'One conversion, two jobs.');

        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL . '?q=' . urlencode(self::STORE_A_NAME));
        $I->see('🎙 2');
    }

    public function theUploadedAudioFilterShowsOnlyStoresWithConversions(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL . '?audio=with');
        $I->seeResponseCodeIs(200);
        $I->see(self::STORE_A_NAME);
        $I->dontSee(self::STORE_B_NAME);
        $I->dontSee(self::STORE_C_NAME);
    }

    /**
     * The filter narrows the rows, the total **and** the alphabet counts together.
     *
     * This is why it is applied inside the directory query rather than by hiding cards afterwards: a
     * filter that only hid cards would leave the pager and the letter counts promising stores that
     * render nowhere.
     */
    public function theUploadedAudioFilterNarrowsTheAlphabetCountsToo(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL);
        $unfiltered = $this->alphabetTotal($I);

        $I->amOnPage(self::PICKER_URL . '?audio=with');
        $filtered = $this->alphabetTotal($I);

        Assert::assertLessThan(
            $unfiltered,
            $filtered,
            'The audio filter must narrow the alphabet total, not just the visible cards.',
        );
        Assert::assertGreaterThan(0, $filtered);
    }

    /** A store with nothing uploaded reads zero rather than being absent from the count map. */
    public function aStoreWithNoAudioShowsZero(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL . '?q=' . urlencode(self::STORE_B_NAME));
        $I->see(self::STORE_B_NAME);
        $I->see('🎙 0');
    }

    public function thePickerCanBeSearched(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL . '?q=' . urlencode('Audio Store Alpha'));
        $I->seeResponseCodeIs(200);
        $I->see(self::STORE_A_NAME);
        $I->dontSee(self::STORE_B_NAME);
    }

    // -------------------------------------------------------------------------- the store page

    public function anUnknownStoreLeadsBackToThePicker(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage('/audio-to-text/store/424242424');
        $I->seeCurrentUrlEquals(self::PICKER_URL);
    }

    /** The route constraint rejects a non-numeric id before any action runs. */
    public function aMalformedStoreIdIsNotFound(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage('/audio-to-text/store/not-a-number');
        $I->seeResponseCodeIs(404);
    }

    /**
     * One form in a dialog, where there were three cards side by side.
     *
     * What the three cards each carried, this one form still carries — the file, the COMMON mode, the
     * progress element the upload script binds to — because the request behind it did not change. The
     * only thing that moved is how the type is named: a radio group instead of three hidden inputs.
     */
    public function theStorePageOffersOneUploadFormForEveryRecordingType(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->seeResponseCodeIs(200);
        $I->see(self::STORE_A_NAME);
        $I->see('Add audio');
        $I->dontSee('Separate Customer and Agent recordings');

        $I->seeElement($this->uploadForm() . ' input[name=audio]');
        $I->seeElement($this->uploadForm() . ' input[name=mode][value=COMMON]');
        $I->seeElement($this->uploadForm() . ' progress');

        // One form, not four: the three cards are gone rather than hidden.
        $I->dontSeeElement('#a2t-common-form');
        $I->dontSeeElement('#a2t-caller-form');
        $I->dontSeeElement('#a2t-callee-form');
    }

    // ---------------------------------------------------------------------------- common mode

    public function aMixedRecordingQueuesOneJobForThisStore(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $conversations = $this->conversationsFor(self::STORE_A);
        Assert::assertCount(1, $conversations);
        Assert::assertSame('COMMON', $conversations[0]['mode']);

        $children = $this->childrenOf((int) $conversations[0]['id']);
        Assert::assertCount(1, $children);
        Assert::assertSame('COMMON', $children[0]['source_role']);
        Assert::assertSame('QUEUED', $children[0]['status']);
        Assert::assertNull($children[0]['transcript'], 'The web request must not transcribe anything.');
    }

    public function callerAndCalleeRecordingsUseTheExistingSingleFileQueue(WebTester $I): void
    {
        $this->signIn($I);
        foreach (['CALLER', 'CALLEE'] as $type) {
            $this->uploadCard($I, self::STORE_A, $type);
            $conversation = $this->conversationsFor(self::STORE_A)[0];
            Assert::assertSame('COMMON', $conversation['mode']);
            $children = $this->childrenOf((int) $conversation['id']);
            Assert::assertCount(1, $children);
            Assert::assertSame('QUEUED', $children[0]['status']);
            Assert::assertNull($children[0]['transcript']);
        }
        Assert::assertCount(2, $this->conversationsFor(self::STORE_A));
    }

    /**
     * A common upload lands on the correction page, the same place the global conversions list sends
     * its View action — so "View" means one thing wherever it is pressed.
     *
     * The conversion page redirects rather than reimplementing anything, so every existing screen
     * still applies to a mixed recording unchanged. A job with nothing to correct redirects itself on
     * to the detail page, which is why this is safe for a recording that has only just been queued.
     */
    public function aMixedRecordingLandsOnItsCorrectionPage(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $children = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id']);

        // Queued, so /review has nothing to show and hands it on to the detail page. Both hops are
        // the point: the link goes to /review, and /review never dead-ends.
        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $children[0]['public_id']);
    }

    /** A completed conversion stays on /review, which is where the correcting is done. */
    public function aCompletedMixedRecordingOpensTheCorrectionPage(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $conversation = $this->conversationsFor(self::STORE_A)[0];
        $child = $this->childrenOf((int) $conversation['id'])[0];

        // No worker runs during this suite, so the completed state is written by hand — exactly the
        // shape the worker leaves behind for a mixed recording whose speakers were separated.
        $this->completeWithSeparation((string) $child['public_id']);

        $I->amOnPage('/audio-to-text/conversion/' . $conversation['public_id']);
        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $child['public_id'] . '/review');
        $I->seeResponseCodeIs(200);
    }

    /** A mixed recording still needs its speakers worked out, so the pipeline is asked to. */
    public function aMixedRecordingStillAsksForSpeakerSeparation(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $children = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id']);
        Assert::assertSame(
            'PENDING',
            $children[0]['speaker_separation_status'],
            'A common recording must be queued for diarization.',
        );
    }

    // -------------------------------------------------------------------------- separate mode

    public function twoRecordingsQueueTwoJobsUnderOneConversation(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadSeparate($I, self::STORE_A);

        $conversations = $this->conversationsFor(self::STORE_A);
        Assert::assertCount(1, $conversations, 'A pair is one conversation.');
        Assert::assertSame('SEPARATE', $conversations[0]['mode']);

        $children = $this->childrenOf((int) $conversations[0]['id']);
        Assert::assertCount(2, $children);
        Assert::assertSame(
            ['CUSTOMER', 'AGENT'],
            [$children[0]['source_role'], $children[1]['source_role']],
        );
        Assert::assertSame(['QUEUED', 'QUEUED'], [$children[0]['status'], $children[1]['status']]);
    }

    /**
     * The roles were supplied, so nothing is inferred and nothing is claimed.
     *
     * `speaker_separation_status` stays NULL rather than being set to a value that would say a
     * diarizer ran and reached a conclusion. That column exists to distinguish a measurement from an
     * assumption, and writing one for a fact we were told would defeat it.
     */
    public function suppliedRolesAreNeverQueuedForDiarization(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadSeparate($I, self::STORE_A);

        foreach ($this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id']) as $child) {
            Assert::assertNull(
                $child['speaker_separation_status'],
                'A recording whose role was supplied must not be queued for diarization.',
            );
        }
    }

    /**
     * One bad file rejects the whole submission.
     *
     * The alternative — accepting the Customer and reporting the Agent — leaves a conversation
     * promising two recordings and holding one, which is the state the paired transaction exists to
     * make impossible.
     */
    public function oneBadFileLeavesNoHalfCreatedPair(WebTester $I): void
    {
        $this->signIn($I);

        $this->postSeparateAudio($I, $this->storeUrl(self::STORE_A), [
            'customer_audio' => 'kf_store_valid.wav',
            'agent_audio' => 'kf_store_fake.txt',
        ]);

        $I->see('Agent audio: Only .wav, .mp3, .m4a, .ogg, .webm files are supported.');
        Assert::assertSame([], $this->conversationsFor(self::STORE_A));
        Assert::assertSame(0, $this->jobCountFor(self::STORE_A));
    }

    public function bothMissingRecordingsAreReportedAtOnce(WebTester $I): void
    {
        $this->signIn($I);

        $this->postSeparateAudio($I, $this->storeUrl(self::STORE_A));

        $I->see('Customer audio: Choose an audio file first.');
        $I->see('Agent audio: Choose an audio file first.');
        Assert::assertSame([], $this->conversationsFor(self::STORE_A));
    }

    // ----------------------------------------------- where a finished conversion opens

    /**
     * The destination a finished job opens at, named by the server on the page that waits for it.
     *
     * Both pollers — the upload card on the store page and the job page's own — read this one
     * attribute instead of assembling a URL, which is what keeps the token current, the deployment
     * prefix intact and no host written down anywhere. Asserted for all three cards, because all three
     * go through the same flow and a regression in one would be a regression in all.
     *
     * @example ["common"]
     * @example ["caller"]
     * @example ["callee"]
     */
    public function aPendingJobNamesItsOwnReviewPageAsTheFinishedDestination(
        WebTester $I,
        \Codeception\Example $example,
    ): void {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, (string) $example[0]);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $token = (string) $child['public_id'];

        // Still queued, so the upload lands on the detail page and the page advertises where to go
        // once conversion succeeds. The token is this job's own — nothing is hardcoded.
        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $token);
        $I->seeElement('[data-a2t-poll][data-a2t-done="/audio-to-text/job/' . $token . '/review"]');
    }

    /**
     * A terminal job advertises nothing, which is what stops the two pages bouncing.
     *
     * The completed page carries no poller and no destination: a job handed back here by /review —
     * because it had nothing to correct — has nothing left to send it away again.
     */
    public function aFinishedJobPageAdvertisesNoDestinationAndNoPolling(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation((string) $child['public_id']);

        $I->amOnPage('/audio-to-text/job/' . $child['public_id']);
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('[data-a2t-poll]');
        $I->dontSeeElement('[data-a2t-done]');
    }

    /**
     * The destination itself: a completed job's review page loads, for every card.
     *
     * @example ["common"]
     * @example ["caller"]
     * @example ["callee"]
     */
    public function aCompletedJobsReviewPageLoadsForEveryCard(WebTester $I, \Codeception\Example $example): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, (string) $example[0]);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $token = (string) $child['public_id'];
        $this->completeWithSeparation($token);

        $I->amOnPage('/audio-to-text/job/' . $token . '/review');
        $I->seeResponseCodeIs(200);
        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $token . '/review', 'No further hop: this is the destination.');
    }

    /** A failed job is never sent to a correction screen — the error is on the detail page. */
    public function aFailedJobIsHandedBackToItsDetailPageRatherThanToReview(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['status' => 'FAILED', 'processing_stage' => 'FAILED', 'error_message' => 'Transcription failed.'],
            ['public_id' => $child['public_id']],
        )->execute();

        $I->amOnPage('/audio-to-text/job/' . $child['public_id'] . '/review');
        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $child['public_id']);
        $I->see('Transcription failed.');
        // And nothing on that page tries to send the reader anywhere: a failure is terminal.
        $I->dontSeeElement('[data-a2t-done]');
    }

    /** A job still converting keeps the behaviour it had: the detail page, polling, no redirect. */
    public function aProcessingJobStaysOnItsDetailPage(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['status' => 'PROCESSING', 'processing_stage' => 'TRANSCRIBING'],
            ['public_id' => $child['public_id']],
        )->execute();

        $I->amOnPage('/audio-to-text/job/' . $child['public_id'] . '/review');
        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $child['public_id']);
        $I->seeElement('[data-a2t-poll]');
    }

    /** The plain job route keeps working on its own — it is still where a pending job waits. */
    public function theJobDetailRouteRemainsReachableDirectly(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];

        $I->amOnPage('/audio-to-text/job/' . $child['public_id']);
        $I->seeResponseCodeIs(200);
        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $child['public_id']);
    }

    // ------------------------------------------------- recording type and the optional order id

    /**
     * The form offers every type, and the optional order field.
     *
     * The radio group is what lets the server tell three otherwise identical submissions apart — they
     * post the same mode, the same field names and the same file input — so a missing value would
     * silently return the listing to filing every upload under Mix / Common.
     */
    public function theUploadFormDeclaresEveryRecordingTypeAndOffersAnOptionalOrderId(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->storeUrl(self::STORE_A));

        foreach (['MIXED', 'CALLER', 'CALLEE'] as $type) {
            $I->seeElement($this->uploadForm() . ' input[name=recording_type][value=' . $type . ']');
        }

        // Mixed is the default, so the commonest upload needs no choice made for it.
        $I->seeElement($this->uploadForm() . ' input[name=recording_type][value=MIXED][checked]');
        $I->seeElement($this->uploadForm() . ' input[name=order_id]');
        $I->see('Optional. Example: 16513791');
    }

    /**
     * An upload with an order id: both values persisted, and the recording lands in its own column.
     *
     * The type is no longer a word in a cell — it is *which cell*. Asserting on the column is what
     * makes this test notice a caller recording filed under Mix / Common, which is the mistake the
     * grouped table exists to make visible.
     *
     * @example ["MIXED"]
     * @example ["CALLER"]
     * @example ["CALLEE"]
     */
    public function anUploadPersistsItsTypeAndOrderIdAndFilesItUnderTheOrder(
        WebTester $I,
        \Codeception\Example $example,
    ): void {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, (string) $example[0], '16513791');

        $conversation = $this->conversationsFor(self::STORE_A)[0];
        Assert::assertSame($example[0], $conversation['recording_type']);
        Assert::assertSame('16513791', $conversation['order_id']);
        // The mode is untouched: all three are still COMMON uploads with one COMMON child, which is
        // what keeps the transcription path identical for all of them.
        Assert::assertSame('COMMON', $conversation['mode']);
        $children = $this->childrenOf((int) $conversation['id']);
        Assert::assertCount(1, $children);
        Assert::assertSame('COMMON', $children[0]['source_role']);
        Assert::assertSame('QUEUED', $children[0]['status']);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->see('Order ID');
        $I->see('#16513791');
        $this->seeRecordingInColumn($I, (string) $example[0]);
    }

    /**
     * The same three uploads with the field left empty: still accepted, still typed, order id NULL.
     *
     * @example ["MIXED"]
     * @example ["CALLER"]
     * @example ["CALLEE"]
     */
    public function anUploadWithoutAnOrderIdStoresNullAndStillGetsItsOwnRow(
        WebTester $I,
        \Codeception\Example $example,
    ): void {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, (string) $example[0]);

        $conversation = $this->conversationsFor(self::STORE_A)[0];
        Assert::assertSame($example[0], $conversation['recording_type'], 'The type is recorded either way.');
        Assert::assertNull($conversation['order_id'], 'No order means NULL, never 0 and never an empty string.');
        Assert::assertCount(1, $this->childrenOf((int) $conversation['id']), 'The upload still queued.');

        $I->amOnPage($this->storeUrl(self::STORE_A));
        // Said in words rather than left blank, which would read as missing data — and it is still a
        // row of its own rather than a shared "no order" bucket.
        $I->see('No order');
        $this->seeRecordingInColumn($I, (string) $example[0]);
    }

    /**
     * A refused order id stops the upload before anything exists.
     *
     * Not merely "the row has no order id": no conversation, no job, and therefore nothing for the
     * worker to pick up. An upload that is half-accepted is worse than one that is refused.
     *
     * @example ["abc"]
     * @example ["16513791abc"]
     * @example ["58-100234"]
     * @example ["-16513791"]
     * @example ["165 13791"]
     */
    public function anInvalidOrderIdIsRefusedAndQueuesNothing(WebTester $I, \Codeception\Example $example): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED', (string) $example[0]);

        Assert::assertSame([], $this->conversationsFor(self::STORE_A), 'Nothing may be created.');
        Assert::assertSame(0, $this->jobCountFor(self::STORE_A), 'Transcription must not start.');
        $I->see('Order ID must be digits only');
        // What was typed comes back, so a typo can be corrected rather than retyped from memory —
        // and the dialog holding it is rendered already open, because a refusal has to be visible.
        $I->seeElement($this->uploadForm() . ' input[name=order_id][value="' . $example[0] . '"]');
        $I->seeElement('.a2t-upload-dialog[open]');
    }

    /** A posted type outside the allow-list records nothing and is never stored. */
    public function aTamperedRecordingTypeIsNeverPersisted(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->attachFile('#a2t-audio', 'kf_store_valid.wav');
        $I->submitForm($this->uploadForm(), ['recording_type' => 'PRESIDENT']);

        $conversation = $this->conversationsFor(self::STORE_A)[0];
        Assert::assertNull($conversation['recording_type'], 'Only the three known values may be stored.');
        // The upload itself is unaffected: it is the ordinary COMMON upload it has always been.
        Assert::assertSame('COMMON', $conversation['mode']);

        // A row with no recording type is a COMMON upload, which is what the Mix / Common column has
        // always meant — read that way on the page, never written back to the database.
        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->see('Mix / Common');
        $this->seeRecordingInColumn($I, 'MIXED');
    }

    /** A Customer + Agent pair records no type: its mode already describes it, and still does. */
    public function aSeparatePairRecordsNoRecordingTypeAndKeepsItsLabel(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadSeparate($I, self::STORE_A);

        $conversation = $this->conversationsFor(self::STORE_A)[0];
        Assert::assertSame('SEPARATE', $conversation['mode']);
        Assert::assertNull($conversation['recording_type']);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->see('Customer + Agent');
        // Not relabelled into the new vocabulary: Caller and Callee are empty for this row, because
        // nothing here ever established which of the two placed the call.
        $I->seeElement('.a2t-orders tbody tr:first-child td:nth-child(3) .util-muted');
        $I->seeElement('.a2t-orders tbody tr:first-child td:nth-child(4) .util-muted');
    }

    /**
     * An upload made before either column existed still renders, and still reads as it always did.
     *
     * Written straight into the table with both new columns NULL, which is exactly what every one of
     * the rows already in this database looks like.
     */
    public function anUploadFromBeforeTheseColumnsStillRendersUnchanged(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $this->connection->createCommand()->update(
            '{{%audio_conversations}}',
            ['recording_type' => null, 'order_id' => null],
            ['store_source_id' => self::STORE_A],
        )->execute();

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->seeResponseCodeIs(200);
        // A row that predates recording types is read as the COMMON upload it is, and an upload
        // that named no order is still a row of its own.
        $I->see('Mix / Common');
        $I->see('No order');
        $I->see('1 order for this store');
    }

    /** The provider choice still rides through an upload that also carries an order id. */
    public function theProviderChoiceIsUnaffectedByTheNewFields(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $conversation = $this->conversationsFor(self::STORE_A)[0];
        $children = $this->childrenOf((int) $conversation['id']);

        Assert::assertSame('WHISPER', $children[0]['transcription_provider']);
        Assert::assertSame('CALLER', $conversation['recording_type']);
        Assert::assertSame('16513791', $conversation['order_id']);
    }

    // ----------------------------------------------------------------------- the store history

    /** One paired upload is one row and one count, however many jobs are underneath it. */
    public function aPairIsOneRowInTheStoresHistory(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadSeparate($I, self::STORE_A);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        // Its halves are Customer and Agent — roles the administrator supplied — and this application
        // has never known which of them called whom, so they keep their own names rather than being
        // relabelled Caller and Callee.
        $I->see('Customer + Agent');
        $I->see('1 order for this store');
        Assert::assertSame(2, $this->jobCountFor(self::STORE_A), 'Two jobs, one row.');
    }

    public function aStoresHistoryShowsOnlyItsOwnUploads(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage($this->storeUrl(self::STORE_B));
        $I->seeResponseCodeIs(200);
        $I->see('No audio recordings yet.');
        Assert::assertSame([], $this->conversationsFor(self::STORE_B));
    }

    /**
     * The store comes from the URL and nowhere else.
     *
     * A posted `store_id` naming another store must change nothing — the route already says which
     * store this is, and reading the body for it would let anyone who can reach one store's page write
     * onto another's history.
     */
    public function aPostedStoreIdIsIgnored(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->attachFile('#a2t-audio', 'kf_store_valid.wav');
        $I->submitForm($this->uploadForm(), ['store_id' => (string) self::STORE_B]);

        Assert::assertCount(1, $this->conversationsFor(self::STORE_A));
        Assert::assertSame([], $this->conversationsFor(self::STORE_B));
    }

    // ------------------------------------------------------------- the global conversions list

    /**
     * The global list is the technical view — one row per recording — and it now names the store each
     * recording was uploaded for, linked to that store's own page.
     *
     * A separate pair is deliberately still **two rows** here. That is the difference between this
     * list and a store's history, and flattening it would hide one of the two jobs the queue actually
     * has to get through.
     */
    public function theConversionsListNamesTheStoreAndLinksToIt(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadSeparate($I, self::STORE_A);

        $I->amOnPage('/audio-to-text/jobs');
        $I->seeResponseCodeIs(200);
        $I->see('Store');
        $I->see(self::STORE_A_NAME);
        $I->seeLink(self::STORE_A_NAME, $this->storeUrl(self::STORE_A));
        $I->see('kf_store_customer.wav');
        $I->see('kf_store_agent.wav');
    }

    /**
     * A conversion uploaded before store-wise audio says so rather than borrowing a store.
     *
     * Those rows were back-filled with a conversation but no store, because there was none to infer.
     */
    public function aConversionWithNoStoreShowsNoStore(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        // Exactly what the migration left on every pre-existing conversion.
        $this->connection->createCommand()->update(
            '{{%audio_conversations}}',
            ['store_source_id' => null],
            ['store_source_id' => self::STORE_A],
        )->execute();

        $I->amOnPage('/audio-to-text/jobs');
        $I->seeResponseCodeIs(200);
        $I->see('kf_store_valid.wav');
        $I->dontSee(self::STORE_A_NAME);
        $I->dontSeeLink(self::STORE_A_NAME);
    }

    /** The picker is where you go to upload; it also has to be a way back to everything already done. */
    public function thePickerLinksToTheGlobalConversionsList(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL);
        $I->seeLink('All conversions', '/audio-to-text/jobs');

        $I->click('All conversions');
        $I->seeCurrentUrlEquals('/audio-to-text/jobs');
        $I->see('Audio conversions');
    }

    // --------------------------------------------------------------------- the conversion page

    public function aSeparateConversionShowsBothRolesSeparately(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadSeparate($I, self::STORE_A);

        $conversation = $this->conversationsFor(self::STORE_A)[0];
        $I->amOnPage('/audio-to-text/conversion/' . $conversation['public_id']);
        $I->seeResponseCodeIs(200);
        $I->see('Customer');
        $I->see('Agent');
        $I->see('kf_store_customer.wav');
        $I->see('kf_store_agent.wav');
        $I->see(self::STORE_A_NAME);
    }

    /**
     * Two files recorded independently carry no shared clock, so there are no turns to order and no
     * speakers to identify. The correction screen is not offered, and the page says why rather than
     * leaving a reader to infer it from a missing button.
     */
    public function aSeparateConversionOffersNoSpeakerCorrection(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadSeparate($I, self::STORE_A);

        $conversation = $this->conversationsFor(self::STORE_A)[0];
        $I->amOnPage('/audio-to-text/conversion/' . $conversation['public_id']);

        $I->dontSeeElement('a[href$="/review"]');
        $I->dontSeeElement('a[href$="/conversation"]');
        $I->see('nothing to correct here');
    }

    // ---------------------------------------------------------------- the original-transcript action

    /**
     * E, F, G. The machine's own transcript is offered exactly where one exists.
     *
     * All three states are asserted in one pass because they are the same decision seen from three
     * sides — an action that shows nothing is worse than no action, so the row offers it only where
     * something was actually transcribed.
     *
     * The action is now a control that opens a dialog rather than a link to a page, and it is
     * addressed by the **group** rather than by one recording: a row is an order, and an order can
     * hold a mixed, a caller and a callee recording that belong in one reading. The page it replaced
     * is untouched and still routed — {@see theOldPerRecordingRoutesStillAnswer()} pins that.
     */
    public function theOriginalTranscriptActionIsOfferedOnlyWhereSomethingWasTranscribed(WebTester $I): void
    {
        $this->signIn($I);

        // F. Common, still queued: no machine transcript yet, so no action.
        $this->uploadCommon($I, self::STORE_A);
        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->dontSeeElement('[data-a2t-transcripts]');

        // E. The same conversion once the worker has finished with it.
        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($child['public_id']);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->see('Original transcript');
        $I->seeElement('[data-a2t-transcripts]');
        // Beside the way in to the corrections, never instead of it.
        $I->seeElement('[data-a2t-details]');

        // G. Separate and still queued: nothing transcribed, so nothing to read. Asserted on the
        // control rather than on the words: the dialog shell is rendered on every load and carries
        // the heading whether or not any row can open it.
        $this->uploadSeparate($I, self::STORE_B);
        $I->amOnPage($this->storeUrl(self::STORE_B));
        $I->dontSeeElement('[data-a2t-transcripts]');
    }

    /**
     * The pages the dialogs replaced are still routed, and still answer.
     *
     * The store page stopped linking to them; nothing removed them. A bookmark, a pasted URL or an
     * older tab has to keep working, and "we redesigned a listing" is not a reason for an address to
     * start 404ing.
     */
    public function theOldPerRecordingRoutesStillAnswer(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $conversation = $this->conversationsFor(self::STORE_A)[0];
        $child = $this->childrenOf((int) $conversation['id'])[0];
        $this->completeWithSeparation($child['public_id']);

        foreach ([
            '/audio-to-text/conversion/' . $conversation['public_id'],
            '/audio-to-text/conversion/' . $conversation['public_id'] . '/ai-audio',
            '/audio-to-text/job/' . $child['public_id'],
            '/audio-to-text/job/' . $child['public_id'] . '/original',
            '/audio-to-text/job/' . $child['public_id'] . '/review',
            '/audio-to-text/job/' . $child['public_id'] . '/conversation',
        ] as $path) {
            $I->amOnPage($path);
            $I->seeResponseCodeIs(200);
        }
    }

    /**
     * The group endpoints answer for their own store, and for no other.
     *
     * Both halves of the address arrive from a browser. A key naming one store's conversation,
     * requested under another store's id, resolves nothing and answers 404 — the same answer a key
     * that never existed gets, because an id that answers differently when it exists is an id that
     * can be used to find out what exists.
     */
    public function aGroupKeyFromAnotherStoreIsNotFound(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($child['public_id']);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $transcripts = $I->grabAttributeFrom('[data-a2t-transcripts]', 'data-a2t-transcripts');

        // Its own store: a transcript, as data.
        $I->amOnPage($transcripts);
        $I->seeResponseCodeIs(200);
        $I->seeInSource('"transcripts"');

        // The same key under a store it does not belong to.
        $I->amOnPage(str_replace(
            '/store/' . self::STORE_A . '/',
            '/store/' . self::STORE_B . '/',
            $transcripts,
        ));
        $I->seeResponseCodeIs(404);
    }

    /**
     * A transcript with no speaker segments is still shown, as one passage.
     *
     * The dedicated Original *page* handles this case by redirecting away — a modal cannot, so the
     * endpoint falls back to the machine's own flat transcript and says so by sending no segments.
     * This is the one place the dialog deliberately differs from the page it stands beside.
     *
     * No speaker is invented for it. A recording whose speakers were never separated has no speakers
     * this application knows, and filling that gap with "Speaker 1" would be a claim rather than a
     * rendering.
     */
    public function anOriginalTranscriptWithNoSegmentsFallsBackToItsPlainText(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            [
                'status' => 'COMPLETED',
                'processing_stage' => 'COMPLETED',
                'transcript' => 'One large pepperoni for pickup.',
                'speaker_segments' => null,
                'speaker_separation_status' => 'UNAVAILABLE',
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['public_id' => $child['public_id']],
        )->execute();

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->amOnPage($I->grabAttributeFrom('[data-a2t-transcripts]', 'data-a2t-transcripts'));

        $I->seeResponseCodeIs(200);
        $I->seeInSource('One large pepperoni for pickup.');
        $I->seeInSource('"segments":[]');
    }

    /**
     * The Original transcript is the machine's own, whatever has been corrected since.
     *
     * This is the entire distinction between the two dialogs: one shows what the transcriber produced
     * and the other shows the version being worked on. Reading the reviewed columns here would merge
     * them and leave an administrator no way to compare the two — which is what the page exists for.
     */
    public function anOriginalTranscriptNeverReturnsACorrection(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($child['public_id']);

        // A correction that says something the machine never said.
        $reviewed = json_encode([
            ['start_ms' => 0, 'end_ms' => 2000, 'speaker' => 'A', 'role' => 'CUSTOMER',
                'text' => 'CORRECTED BY A HUMAN', 'confidence' => 0.9, 'approx' => false],
        ], JSON_THROW_ON_ERROR);

        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['reviewed_segments' => $reviewed, 'review_count' => 1],
            ['public_id' => $child['public_id']],
        )->execute();

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->amOnPage($I->grabAttributeFrom('[data-a2t-transcripts]', 'data-a2t-transcripts'));

        $I->seeResponseCodeIs(200);
        $I->seeInSource('Can I get a shrimp fried rice?');
        $I->dontSeeInSource('CORRECTED BY A HUMAN');

        // And the Details dialog, which is the other half of the comparison, shows the correction.
        $I->amOnPage('/audio-to-text/job/' . $child['public_id'] . '/review/fragment');
        $I->seeResponseCodeIs(200);
        $I->seeInSource('CORRECTED BY A HUMAN');
    }

    /** A recording with neither segments nor text is offered no tab at all. */
    public function aRecordingWithNothingTranscribedIsNotOfferedATab(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($child['public_id']);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $transcripts = $I->grabAttributeFrom('[data-a2t-transcripts]', 'data-a2t-transcripts');

        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['transcript' => null, 'speaker_segments' => null, 'customer_text' => null, 'agent_text' => null],
            ['public_id' => $child['public_id']],
        )->execute();

        $I->amOnPage($transcripts);
        $I->seeResponseCodeIs(200);
        $I->seeInSource('"transcripts":[]');
    }

    /**
     * The exact field names the dialogs read.
     *
     * The browser cannot be unit-tested here, so this stands in for it: every key
     * `assets/audio-store/audio-store.js` reaches for is named once, in a test that fails loudly if
     * the server stops sending it. Without this, renaming a field would leave the endpoints green,
     * the page green, and one dialog quietly blank.
     */
    public function theTranscriptsEndpointSendsWhatTheDialogReads(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($child['public_id']);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->amOnPage($I->grabAttributeFrom('[data-a2t-transcripts]', 'data-a2t-transcripts'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);

        Assert::assertSame(['orderId', 'transcripts'], array_keys($payload));

        /** @var list<array<string, mixed>> $transcripts */
        $transcripts = $payload['transcripts'];
        Assert::assertCount(1, $transcripts);
        Assert::assertSame(
            [
                'type', 'label', 'conversationPublicId', 'jobPublicId', 'provider',
                'duration', 'uploadedAt', 'segments', 'plainText',
            ],
            array_keys($transcripts[0]),
        );

        /** @var list<array<string, mixed>> $segments */
        $segments = $transcripts[0]['segments'];
        Assert::assertNotSame([], $segments);
        // The same turn shape the Details dialog receives, because one renderer draws both.
        Assert::assertSame(
            ['start', 'end', 'speaker', 'speakerConfirmed', 'text', 'side', 'time', 'delay', 'edited'],
            array_keys($segments[0]),
        );
        Assert::assertContains($segments[0]['side'], ['left', 'right', 'neutral']);
        // Segments and plain text are alternatives, never both: the dialog renders one or the other.
        Assert::assertNull($transcripts[0]['plainText']);
    }

    public function theTtsOptionsEndpointSendsWhatTheDialogReads(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($child['public_id']);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->amOnPage($I->grabAttributeFrom('[data-a2t-tts]', 'data-a2t-tts'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);

        Assert::assertSame(['orderId', 'providerConfigured', 'options'], array_keys($payload));

        /** @var list<array<string, mixed>> $options */
        $options = $payload['options'];
        Assert::assertCount(1, $options);

        // The three the form posts back verbatim, and the three the dialog renders. The browser is
        // never asked to work out which job or which output type a choice means.
        foreach (['recordingType', 'label', 'conversationPublicId', 'jobPublicId', 'outputType',
            'action', 'selectable', 'expectedHash', 'state', 'reason'] as $field) {
            Assert::assertArrayHasKey($field, $options[0], $field . ' is read by the generate dialog.');
        }

        Assert::assertIsBool($options[0]['selectable']);
        Assert::assertStringEndsWith('/ai-audio/generate', (string) $options[0]['action']);
    }

    /**
     * The Text to Audio cell is a two-column grid, not a stack of wrapped phrases.
     *
     * The label and its state are siblings in one grid so every status starts at the same x, and both
     * are `nowrap` so "Common / Mixed" and "Not generated" each stay on one line. Before this the
     * column was narrow enough to break them mid-phrase, which read as two recordings where there
     * was one.
     */
    public function theTextToAudioCellKeepsEachRecordingOnOneLine(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage($this->storeUrl(self::STORE_A));

        $cell = '.a2t-orders tbody tr:first-child td:nth-child(6)';
        $I->seeElement($cell . ' .a2t-tts-list');
        // Direct children of the grid, not wrapped in a row: the grid is what aligns the columns.
        $I->seeElement($cell . ' .a2t-tts-list > .a2t-tts__label');
        $I->seeElement($cell . ' .a2t-tts-list > .a2t-tts__state');
        $I->dontSeeElement($cell . ' .a2t-tts__row');
    }

    /**
     * Both dialogs draw the conversation with the application's own chat classes.
     *
     * Asserted on the scaffolding rather than on the bubbles, which JavaScript builds: `a2t-review` is
     * what gives the Details dialog the correction page's stacked controls, margin icons and Agent
     * tint, and `a2t-chat__scroll` is what the thread's own gutter and bubble widths hang off. A
     * second, private chat design in this dialog is exactly what these two classes prevent.
     *
     * `a2t-chat` is deliberately **absent**: `.app:has(.a2t-chat)` pins the whole shell to 100vh,
     * which is right for a page that is only a conversation and wrong for a table with a dialog over
     * it.
     */
    public function theConversationDialogsReuseTheApplicationsChatLayout(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->storeUrl(self::STORE_A));

        $I->seeElement('.a2t-review-dialog [data-a2t-review-body].a2t-review');
        $I->seeElement('.a2t-review-dialog [data-a2t-review-scroll].a2t-chat__scroll');
        $I->seeElement('.a2t-transcript-dialog [data-a2t-transcript-scroll].a2t-chat__scroll');

        // Read-only, so it must not pick up the scope that draws editing controls.
        $I->dontSeeElement('.a2t-transcript-dialog .a2t-review');
        $I->dontSeeElement('.a2t-review-dialog .a2t-chat');
        $I->dontSeeElement('.a2t-transcript-dialog .a2t-chat');
    }

    /**
     * The dialog offers the two controls the correction page offers, drawn from the same icons.
     *
     * Two, not three: the handle and the pencil are what that page puts beside a bubble, so a third
     * control here would be an action this feature does not otherwise have. Split is deliberately
     * absent — the page offers it only inside its `<noscript>` fallback, which a scripting browser
     * never builds.
     */
    public function theDetailsDialogCarriesTheSameTwoControlsAsTheReviewPage(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->storeUrl(self::STORE_A));

        foreach (['move', 'edit'] as $icon) {
            $I->seeElement('[data-a2t-iconbank] template[data-a2t-icon="' . $icon . '"]');
        }

        $I->dontSeeElement('[data-a2t-iconbank] template[data-a2t-icon="more"]');
        $I->dontSeeElement('[data-a2t-iconbank] template[data-a2t-icon="split"]');
    }

    /**
     * Both confirmations are the correction page's own, rendered from the shared partial.
     *
     * The dialog does not ask a different question before joining two messages, because it renders
     * the same words from the same file. Its version field is left empty for the script: the dialog
     * re-reads the conversation after every correction, so the number it must carry changes while
     * the page under it does not.
     */
    public function theStorePageRendersTheSharedReviewConfirmations(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->storeUrl(self::STORE_A));

        $I->seeElement('dialog.a2t-confirm[data-a2t-move-dialog] form[data-a2t-move-form]');
        $I->seeElement('dialog.a2t-confirm[data-a2t-merge-dialog] form[data-a2t-merge-form]');
        $I->see('Merge these messages?');
        $I->see('Move this text?');

        $I->seeElement('[data-a2t-move-form] input[name="expected_review_count"][data-a2t-version][value=""]');
        // A partial merge's range fields start disabled, so a whole-turn merge never sends them.
        $I->seeElement('[data-a2t-merge-form] input[name="selection_start"][disabled]');
    }

    /**
     * The Details trigger must not look like the correction page's root.
     *
     * `admin.js` finds that page by `document.querySelector('[data-a2t-review]')`. A button carrying
     * the same bare attribute made this listing look like a correction page to that block, which
     * would install its drag and selection handlers over a table. Named apart so it cannot recur.
     */
    public function theDetailsTriggerIsNotMistakenForTheCorrectionPageRoot(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($child['public_id']);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->seeElement('[data-a2t-details]');
        $I->dontSeeElement('[data-a2t-review]');
    }

    /**
     * No dialog is rendered open on an ordinary load.
     *
     * A `<dialog>` with the `open` attribute is visible but **not** in the top layer: no backdrop, no
     * centring, and it lays out as a block after the page content. The one exception is deliberate —
     * the upload dialog reopens itself when a submission was refused, because the errors are inside
     * it — and `anInvalidOrderIdIsRefusedAndQueuesNothing` pins that case.
     */
    public function noDialogIsRenderedOpenOnAnOrdinaryLoad(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->seeElement('.a2t-transcript-dialog');
        $I->dontSeeElement('dialog[open]');
    }

    /** Every dialog closes the way the rest of this application closes a dialog. */
    public function everyDialogClosesWithTheApplicationsCloseControl(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->storeUrl(self::STORE_A));

        $I->seeElement('.source-modal__close[aria-label="Close"]');
        // Not an oversized button with the word on it, which is not what any other dialog here does.
        $I->dontSeeElement('.source-modal__close.btn');
    }

    /** The generate options are store-scoped too, by the same finder and for the same reason. */
    public function aTtsOptionsKeyFromAnotherStoreIsNotFound(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $options = $I->grabAttributeFrom('[data-a2t-tts]', 'data-a2t-tts');

        $I->amOnPage($options);
        $I->seeResponseCodeIs(200);

        $I->amOnPage(str_replace(
            '/store/' . self::STORE_A . '/',
            '/store/' . self::STORE_B . '/',
            $options,
        ));
        $I->seeResponseCodeIs(404);
    }

    /** A key this application could never have issued is refused before it reaches a query. */
    public function aMalformedGroupKeyIsNotFound(WebTester $I): void
    {
        $this->signIn($I);

        foreach ([
            'order:abc',
            'order:-1',
            'store:16513791',
            'conversation:' . str_repeat('z', 32),
        ] as $key) {
            $I->amOnPage($this->storeUrl(self::STORE_A) . '/group/' . $key . '/transcripts');
            $I->seeResponseCodeIs(404);
        }
    }

    public function anUnknownConversionLeadsToTheConversionsList(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage('/audio-to-text/conversion/' . str_repeat('f', 32));
        $I->seeCurrentUrlEquals('/audio-to-text/jobs');
    }

    public function aMalformedConversionIdIsNotFound(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage('/audio-to-text/conversion/nope');
        $I->seeResponseCodeIs(404);
    }

    /** Nothing on these pages may reveal where anything lives on this server. */
    public function noStorePageLeaksAServerPath(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadSeparate($I, self::STORE_A);

        $conversation = $this->conversationsFor(self::STORE_A)[0];

        foreach ([
            self::PICKER_URL,
            $this->storeUrl(self::STORE_A),
            '/audio-to-text/conversion/' . $conversation['public_id'],
        ] as $path) {
            $I->amOnPage($path);
            $I->dontSee('/var/www/');
            $I->dontSee('/opt/whisper');
            $I->dontSee('runtime/audio-to-text');
        }
    }

    /** A filename is attacker-controlled text, and it is rendered on three screens. */
    public function aFilenameContainingMarkupIsEscaped(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $conversationId = (int) $this->conversationsFor(self::STORE_A)[0]['id'];
        $child = $this->childrenOf($conversationId)[0];

        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['original_filename' => '<script>alert(1)</script>.wav'],
            ['public_id' => $child['public_id']],
        )->execute();

        // The listing no longer prints a filename at all — a row is an order, not a file — so the
        // place this value reaches a reader is the Details dialog, which receives it as JSON.
        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->dontSeeElement('script[src=""]');
        $I->dontSee('<script>alert(1)</script>.wav');

        $this->completeWithSeparation($child['public_id']);
        $I->amOnPage('/audio-to-text/job/' . $child['public_id'] . '/review/fragment');
        $I->seeResponseCodeIs(200);
        // Encoded as `\u003Cscript\u003E`, so the value cannot close the document it travels in
        // however it is later inserted.
        $I->dontSeeInSource('<script>');
        $I->seeInSource('alert(1)');
    }

    // ---------------------------------------------------------------------------------- helpers

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    private function storeUrl(int $sourceId): string
    {
        return '/audio-to-text/store/' . $sourceId;
    }

    /**
     * The one upload form, addressed by its own id rather than by position.
     *
     * `form:first-of-type` would keep passing while silently submitting some other form the day one is
     * added above it — and adding a form to a page is exactly the sort of change nobody expects a test
     * to notice.
     */
    private function uploadForm(): string
    {
        return '#a2t-upload-form';
    }

    /** Which column of the grouped table a recording of each type lands in. */
    private const TYPE_COLUMN = ['MIXED' => 2, 'CALLER' => 3, 'CALLEE' => 4];

    private function uploadCommon(WebTester $I, int $sourceId): void
    {
        $this->uploadCard($I, $sourceId, 'MIXED');
    }

    /**
     * Upload one recording of a named type, optionally naming an order.
     *
     * Where three cards each carried a hidden `recording_type`, one form carries a radio group — so
     * the type is named here explicitly rather than implied by which form was submitted. Everything
     * else the request sends is unchanged, which is the point: the pipeline behind it did not move.
     */
    private function uploadCard(WebTester $I, int $sourceId, string $type, string $orderId = ''): void
    {
        $I->amOnPage($this->storeUrl($sourceId));
        $I->attachFile('#a2t-audio', 'kf_store_valid.wav');
        $fields = ['recording_type' => $type];

        if ($orderId !== '') {
            $fields['order_id'] = $orderId;
        }

        $I->submitForm($this->uploadForm(), $fields);
    }

    /**
     * The newest row files its recording under the right column, and under no other.
     *
     * Both halves matter. Seeing the recording where it belongs would still pass if it also appeared
     * in the other two columns, and a caller recording showing up under Mix / Common is precisely the
     * confusion this table was rebuilt to end.
     */
    private function seeRecordingInColumn(WebTester $I, string $type): void
    {
        $row = '.a2t-orders tbody tr:first-child';

        foreach (self::TYPE_COLUMN as $candidate => $column) {
            $cell = $row . ' td:nth-child(' . $column . ')';

            if ($candidate === $type) {
                // Queued, so there is no audio to play yet — but the cell is occupied and says so.
                $I->seeElement($cell . ' .a2t-slot');
            } else {
                // An em dash: nothing was uploaded for this side of the call.
                $I->seeElement($cell . ' .util-muted');
            }
        }
    }

    private function uploadSeparate(WebTester $I, int $sourceId): void
    {
        $this->postSeparateAudio($I, $this->storeUrl($sourceId), [
            'customer_audio' => 'kf_store_customer.wav',
            'agent_audio' => 'kf_store_agent.wav',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conversationsFor(int $sourceId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->from('{{%audio_conversations}}')
            ->where(['store_source_id' => $sourceId])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function childrenOf(int $conversationId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->from('{{%audio_transcription_jobs}}')
            ->where(['conversation_id' => $conversationId])
            ->orderBy(['id' => 'ASC'])
            ->all();

        return $rows;
    }

    /** The "All" bucket of the alphabet strip, which is the directory's own total. */
    private function alphabetTotal(WebTester $I): int
    {
        $count = $I->grabTextFrom('.alpha-nav__item .alpha-nav__count');

        return (int) trim($count);
    }

    private function jobCountFor(int $sourceId): int
    {
        $count = 0;
        foreach ($this->conversationsFor($sourceId) as $conversation) {
            $count += count($this->childrenOf((int) $conversation['id']));
        }

        return $count;
    }

    /** Flips the column the directory and the store page both gate on. */
    private function setStoreActive(int $sourceId, bool $active): void
    {
        $this->connection->createCommand()->update(
            '{{%knowledge_bases}}',
            ['source_active' => $active ? 1 : 0],
            ['source_store_id' => $sourceId],
        )->execute();
    }

    /**
     * Marks a mixed recording complete with a two-speaker split, the way the worker would.
     *
     * Written straight to the machine columns because that is what the worker writes; the reviewed
     * layer stays untouched, so the correction page loads exactly what an uncorrected conversation
     * looks like.
     */
    private function completeWithSeparation(string $publicId): void
    {
        $segments = json_encode([
            ['start_ms' => 0, 'end_ms' => 2000, 'speaker' => 'A', 'role' => 'CUSTOMER',
                'text' => 'Can I get a shrimp fried rice?', 'confidence' => 0.9, 'approx' => false],
            ['start_ms' => 2000, 'end_ms' => 4000, 'speaker' => 'B', 'role' => 'AGENT',
                'text' => 'Sure, for pickup or delivery?', 'confidence' => 0.9, 'approx' => false],
        ], JSON_THROW_ON_ERROR);

        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            [
                'status' => 'COMPLETED',
                'processing_stage' => 'COMPLETED',
                'transcript' => 'Can I get a shrimp fried rice? Sure, for pickup or delivery?',
                'speaker_segments' => $segments,
                'customer_text' => 'Can I get a shrimp fried rice?',
                'agent_text' => 'Sure, for pickup or delivery?',
                'speaker_separation_status' => 'COMPLETED',
                'speaker_separation_method' => 'test',
                'speaker_role_confidence' => 0.9,
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['public_id' => $publicId],
        )->execute();
    }

    private function createStore(int $sourceId, string $name, bool $active = true): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => $name,
            'active' => $active ? 1 : 0,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        // Both rows: the lookup joins the store to its knowledge base, because the name on the page
        // has to be the name on the card that led there.
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => $name,
            'slug' => 'kf-audio-store-' . $sourceId,
            'source_system' => 'order58',
            'source_store_id' => $sourceId,
            'source_name' => $name,
            // The directory reads source-active from the knowledge base, not the store row, so this
            // is the column the picker actually gates on.
            'source_active' => $active ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /**
     * Scoped to this suite's own rows, never a blanket delete.
     *
     * This database is shared with real use and conversations are kept indefinitely, so a
     * `DELETE FROM audio_transcription_jobs` here would destroy someone's actual recordings.
     * Children before parents before administrators: both foreign keys are RESTRICT.
     */
    private function cleanup(): void
    {
        // Scoped by **this suite's own administrator**, not by store.
        //
        // Scoping by `store_source_id` looks equivalent and is not: a test may legitimately null a
        // conversation's store — `aConversionWithNoStoreShowsNoStore` does exactly that, because it is
        // the state the migration left on every pre-existing row — and such a conversation then
        // matches no store, survives the teardown, and blocks its administrator from being removed on
        // the uploader's RESTRICT key. The uploader is the one column no test has a reason to change.
        //
        // Children before parents before administrators: both foreign keys are RESTRICT.
        $adminIds = (new Query($this->connection))
            ->select('id')
            ->from('{{%admin_users}}')
            ->where(['username' => self::ADMIN])
            ->column();

        if ($adminIds !== []) {
            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['uploaded_by_admin_id' => $adminIds])
                ->execute();
            $this->connection->createCommand()
                ->delete('{{%audio_conversations}}', ['uploaded_by_admin_id' => $adminIds])
                ->execute();
        }

        IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => self::ADMIN]);

        foreach ([self::STORE_A, self::STORE_B, self::STORE_C] as $sourceId) {
            IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['source_store_id' => $sourceId]);
            IntegrationDb::cleanup($this->connection, '{{%order58_stores}}', ['source_id' => $sourceId]);
        }

        $this->removeFixtures();
    }

    /**
     * Generated rather than checked in, matching the other audio suites — no opaque binary in the
     * repository, and the bytes libmagic is shown are visible in the diff.
     */
    private function writeFixtures(): void
    {
        // A second of silence, and genuinely so: real ffprobe reads these files and a header claiming
        // zero data bytes has no duration to report, which the duration check then rejects.
        $samples = str_repeat(pack('v', 0), 8000);
        $data = 'data' . pack('V', strlen($samples)) . $samples;
        $fmt = 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
            . pack('V', 8000) . pack('V', 16000) . pack('v', 2) . pack('v', 16);
        $body = 'WAVE' . $fmt . $data;
        $wav = 'RIFF' . pack('V', strlen($body)) . $body;

        foreach (['kf_store_valid.wav', 'kf_store_customer.wav', 'kf_store_agent.wav'] as $file) {
            file_put_contents(codecept_data_dir($file), $wav);
        }

        file_put_contents(codecept_data_dir('kf_store_fake.txt'), "not audio\n");
    }

    private function removeFixtures(): void
    {
        foreach ([
            'kf_store_valid.wav',
            'kf_store_customer.wav',
            'kf_store_agent.wav',
            'kf_store_fake.txt',
        ] as $file) {
            $path = codecept_data_dir() . $file;

            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
