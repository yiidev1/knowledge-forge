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

use function codecept_data_dir;
use function file_put_contents;
use function gmdate;
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
        // The forms are not merely disabled in the markup — they are not rendered at all.
        $I->dontSeeElement('#a2t-common-form');
        $I->dontSeeElement('#a2t-separate-form');
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
        $I->seeElement('#a2t-common-form');
        $I->attachFile('#a2t-audio', 'kf_store_valid.wav');

        // Order58 deactivates the store while the administrator is still looking at the form.
        $this->setStoreActive(self::STORE_C, false);

        $I->submitForm('#a2t-common-form', []);

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

    public function theStorePageNamesItsStoreAndOffersBothModes(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->seeResponseCodeIs(200);
        $I->see(self::STORE_A_NAME);
        $I->see('One mixed recording');
        $I->see('Separate Customer and Agent recordings');
        $I->seeElement('input[name=customer_audio]');
        $I->seeElement('input[name=agent_audio]');
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

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->attachFile('#a2t-customer-audio', 'kf_store_valid.wav');
        $I->attachFile('#a2t-agent-audio', 'kf_store_fake.txt');
        $I->submitForm($this->separateForm(), []);

        $I->see('Agent audio: Only .wav, .mp3, .m4a, .ogg, .webm files are supported.');
        Assert::assertSame([], $this->conversationsFor(self::STORE_A));
        Assert::assertSame(0, $this->jobCountFor(self::STORE_A));
    }

    public function bothMissingRecordingsAreReportedAtOnce(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->submitForm($this->separateForm(), []);

        $I->see('Customer audio: Choose an audio file first.');
        $I->see('Agent audio: Choose an audio file first.');
        Assert::assertSame([], $this->conversationsFor(self::STORE_A));
    }

    // ----------------------------------------------------------------------- the store history

    /** One paired upload is one row and one count, however many jobs are underneath it. */
    public function aPairIsOneRowInTheStoresHistory(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadSeparate($I, self::STORE_A);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->see('Separate Customer + Agent');
        $I->see('1 conversion for this store');
        Assert::assertSame(2, $this->jobCountFor(self::STORE_A), 'Two jobs, one conversion.');
    }

    public function aStoresHistoryShowsOnlyItsOwnUploads(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage($this->storeUrl(self::STORE_B));
        $I->seeResponseCodeIs(200);
        $I->see('Nothing uploaded for this store yet.');
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
        $I->submitForm($this->commonForm(), ['store_id' => (string) self::STORE_B]);

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

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->see('<script>alert(1)</script>.wav');
        $I->dontSeeElement('.a2t-cell-file script');
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
     * The forms are addressed by their own ids, not by position.
     *
     * `form:first-of-type` would keep passing while silently submitting the other mode the day the two
     * cards are reordered on the page — and reordering them is exactly the sort of change nobody
     * expects a test to notice.
     */
    private function commonForm(): string
    {
        return '#a2t-common-form';
    }

    private function separateForm(): string
    {
        return '#a2t-separate-form';
    }

    private function uploadCommon(WebTester $I, int $sourceId): void
    {
        $I->amOnPage($this->storeUrl($sourceId));
        $I->attachFile('#a2t-audio', 'kf_store_valid.wav');
        $I->submitForm($this->commonForm(), []);
    }

    private function uploadSeparate(WebTester $I, int $sourceId): void
    {
        $I->amOnPage($this->storeUrl($sourceId));
        $I->attachFile('#a2t-customer-audio', 'kf_store_customer.wav');
        $I->attachFile('#a2t-agent-audio', 'kf_store_agent.wav');
        $I->submitForm($this->separateForm(), []);
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
