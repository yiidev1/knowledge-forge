<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\AudioToText\Application\Tts\TtsRenderKey;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\Shared\Audio\RecordingTypeLabels;
use App\Tests\Support\LegacySeparateAudioUpload;
use App\Tests\Support\TtsRenderSettings;
use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_keys;
use function array_unique;
use function array_map;
use function codecept_data_dir;
use function file_put_contents;
use function gmdate;
use function json_decode;
use function json_encode;
use function is_file;
use function pack;
use function preg_quote;
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

    // --------------------------------------------- the landing state and filter independence

    /**
     * A bare URL is NEUTRAL. The landing state belongs to the menu, not to the page.
     *
     * This was briefly the other way round - the page treated an absent `audio` as Uploaded audio - and
     * it broke every filter link, because `$dirUrl` omits a parameter that equals its own default. See
     * the two tests below for the exact failure that caused.
     */
    public function aBareUrlIsNeutralAndDoesNotForceUploadedAudio(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL);

        $I->seeResponseCodeIs(200);
        $I->see('All stores', '.filter-chip--active');
        $I->dontSee('Uploaded audio', '.filter-chip--active');
        $I->see(self::STORE_B_NAME, '.store-card');
    }

    /** The sidebar is what establishes the landing state, and it says so in the href. */
    public function theSidebarAudioEntryCarriesTheUploadedAudioFilter(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL);

        $I->seeElement('.sidebar__link[href="' . self::PICKER_URL . '?audio=with"]');
    }

    /** And following it lands on Uploaded audio. */
    public function followingTheSidebarEntryLandsOnUploadedAudio(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL . '?audio=with');

        $I->see('Uploaded audio', '.filter-chip--active');
        $I->see(self::STORE_A_NAME, '.store-card');
        $I->dontSee(self::STORE_B_NAME, '.store-card');
    }

    /**
     * THE REGRESSION THIS SUITE EXISTS FOR.
     *
     * From Uploaded audio, clicking All stores must actually switch. The link it generates carries no
     * `audio` parameter at all - the builder omits a value equal to its own default - so a page that
     * read absence as Uploaded audio sent the user straight back where they started and the click
     * looked broken.
     */
    public function clickingAllStoresFromUploadedAudioActuallySwitches(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL . '?audio=with');
        $I->click('All stores', '.filter-bar');

        $I->see('All stores', '.filter-chip--active');
        $I->dontSee('Uploaded audio', '.filter-chip--active');
        $I->see(self::STORE_B_NAME, '.store-card');
    }

    /** Changing the audio axis must not reset the source axis. */
    public function switchingTheAudioFilterPreservesTheSourceFilter(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL . '?status=active&audio=with');
        $I->click('All stores', '.filter-bar');

        $I->see('Source active', '.filter-chip--active');
        $I->see('All stores', '.filter-chip--active');
    }

    /**
     * And the mirror image: changing the source axis must not reset the audio axis - including when
     * the audio axis is at its DEFAULT value, which is the case the omitted parameter used to eat.
     */
    public function switchingTheSourceFilterPreservesTheAudioFilter(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL . '?audio=with');
        $I->click('Source active', '.filter-bar');
        $I->see('Source active', '.filter-chip--active');
        $I->see('Uploaded audio', '.filter-chip--active');

        $I->amOnPage(self::PICKER_URL . '?status=active&audio=all');
        $I->click('All sources', '.filter-bar');
        $I->see('All sources', '.filter-chip--active');
        $I->see('All stores', '.filter-chip--active');
    }

    /** The alphabet is a third independent axis and carries both of the others through. */
    public function theAlphabetPreservesBothFilterGroups(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL . '?status=active&audio=with');
        $I->seeElement('a.alpha-nav__item[href*="status=active"][href*="audio=with"]');
    }

    /** An unrecognised value falls back to All, exactly as the enum always did. */
    public function anUnrecognisedAudioValueFallsBackToAllStores(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL . '?audio=not-a-real-filter');

        $I->see(self::STORE_B_NAME, '.store-card');
        $I->see('All stores', '.filter-chip--active');
    }

    // --------------------------------------------- search preserves the active filters

    /**
     * A GET form submits only its own fields, so the other filters need hidden inputs or a search
     * silently widens the source and audio axes back to everything.
     */
    public function searchPreservesTheSourceAndAudioFilters(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL . '?status=active&audio=with');
        $I->submitForm('.dir-search', ['q' => self::STORE_A_NAME]);

        $I->see('Source active', '.filter-chip--active');
        $I->see('Uploaded audio', '.filter-chip--active');
        $I->see(self::STORE_A_NAME);
    }

    /** A plain search on a plain page stays plain - no parameters that say nothing. */
    public function searchFromANeutralPageCarriesNoFilterParameters(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL);
        $I->submitForm('.dir-search', ['q' => self::STORE_A_NAME]);

        $I->seeInCurrentUrl('q=');
        $I->dontSeeInCurrentUrl('audio=');
        $I->dontSeeInCurrentUrl('status=');
    }

    /** Search resets pagination: page 7 of the old result set means nothing in the new one. */
    public function searchDoesNotCarryThePageNumber(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL . '?audio=with&page=2');
        $I->submitForm('.dir-search', ['q' => self::STORE_A_NAME]);

        $I->dontSeeInCurrentUrl('page=');
    }

    // ------------------------------------------------------- the card breakdown

    /**
     * Every card carries the four-cell strip, zeros included, so the grid keeps one height.
     *
     * The total is deliberately NOT in the strip - it is the pill beside the name, and the same
     * number in two places is two places that can disagree.
     */
    public function eachCardShowsTheRecordingTypeBreakdown(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL);

        $I->seeElement('.store-card__audio');

        // Read from the shared map, not typed out. These cells and the Audio-to-Text table headers name
        // the same three things, and a test carrying its own copy of the words would keep passing while
        // the two screens drifted apart — which is the single failure the shared map exists to prevent.
        //
        // The SHORT form: this strip gives each label a quarter of a card, and the full "Mix / Common"
        // was wider than that — with `white-space: nowrap` it overlapped the cell beside it.
        foreach (array_keys(RecordingTypeLabels::all()) as $stored) {
            $I->see((string) RecordingTypeLabels::shortForStorageValue($stored), '.store-card__audio-label');
        }

        $I->see('Other', '.store-card__audio-label');
        $I->dontSee('Mix / Common', '.store-card__audio-label');
    }

    /**
     * A SEPARATE (Customer + Agent) upload is reported under Other, never as Mixed.
     *
     * This is the live untyped path, not a legacy one: none of MIXED/CALLER/CALLEE describes a pair,
     * so the upload records no recording type at all and its `mode` carries the meaning instead.
     * Counting it as Mixed would claim a channel the uploader never stated - and would still look
     * entirely plausible on screen, which is why it needs a test.
     */
    public function aSeparateUploadIsCountedUnderOtherNotMixed(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadSeparate($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL . '?q=' . urlencode(self::STORE_A_NAME));

        $source = $I->grabPageSource();
        Assert::assertMatchesRegularExpression(
            '~Other</dt>\s*<dd[^>]*>1</dd>~',
            $source,
            'An upload with no recording type belongs under Other.',
        );
        Assert::assertMatchesRegularExpression(
            $this->breakdownCell('MIXED', 0),
            $source,
            'It must NOT be counted as Mixed.',
        );
    }

    /** And a COMMON upload, which does post a recording type, lands in its own named cell. */
    public function aCommonUploadIsCountedUnderItsRecordingType(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage(self::PICKER_URL . '?q=' . urlencode(self::STORE_A_NAME));

        $source = $I->grabPageSource();
        Assert::assertMatchesRegularExpression($this->breakdownCell('MIXED', 1), $source);
        Assert::assertMatchesRegularExpression('~Other</dt>\s*<dd[^>]*>0</dd>~', $source);
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

        $I->amOnPage(self::PICKER_URL . '?audio=all');
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
        $this->seeRecordingInColumn($I, (string) $example[0], canAdd: true);
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
        $this->seeRecordingInColumn($I, (string) $example[0], canAdd: false);
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

    // ------------------------------------------------------------------ add audio to an empty slot

    /**
     * An empty column offers to fill itself, carrying its own recording type.
     *
     * The button opens the SAME Manage Audio dialog the row's own button opens — `RecordingsAction`
     * already emits an entry for all three types whether or not a recording exists — so this is an
     * affordance, not a second upload path. `data-a2t-manage-focus` is the only thing it adds.
     */
    public function anEmptySlotOffersToAddAudioForItsOwnType(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $I->amOnPage($this->storeUrl(self::STORE_A));

        // Column numbers from self::TYPE_COLUMN: MIXED 2, CALLER 3, CALLEE 4.
        $row = '.a2t-orders tbody tr:first-child';
        $I->seeElement($row . ' td:nth-child(2) .a2t-slot__add[data-a2t-manage-focus="MIXED"]');
        $I->seeElement($row . ' td:nth-child(4) .a2t-slot__add[data-a2t-manage-focus="CALLEE"]');
        // The occupied column offers no such thing.
        $I->dontSeeElement($row . ' td:nth-child(3) .a2t-slot__add');
    }

    /** It points at the recordings fragment, which is what the dialog already fetches. */
    public function theAddAudioButtonOpensTheExistingManageDialog(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED', '16513791');

        $I->amOnPage($this->storeUrl(self::STORE_A));

        $I->seeElement('.a2t-slot__add[data-a2t-manage][data-a2t-order="16513791"]');
    }

    /**
     * Manage Audio names no channel, which is what keeps it showing all of them.
     *
     * The dialog now renders one channel when it was opened from a column, and the ONLY thing that
     * tells it which mode it is in is `data-a2t-manage-focus`. So the Actions button must not carry
     * one — a focus attribute added here would quietly turn the full dialog into a single-channel one,
     * and the two other channels would simply stop being reachable from the row.
     */
    public function manageAudioNamesNoChannelSoItStillShowsAllOfThem(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED', '16513791');

        $I->amOnPage($this->storeUrl(self::STORE_A));

        $actions = '.a2t-orders tbody tr:first-child td:last-child';

        $I->seeElement($actions . ' [data-a2t-manage]');
        $I->dontSeeElement($actions . ' [data-a2t-manage-focus]');

        // And the column buttons do carry one, each naming its own column — the pair of facts that
        // makes the two modes distinguishable at all.
        $I->seeElement('.a2t-slot__add[data-a2t-manage-focus="CALLER"]');
        $I->seeElement('.a2t-slot__add[data-a2t-manage-focus="CALLEE"]');
    }

    /**
     * Adding a recording to an empty slot goes through the existing replace endpoint, and the type it
     * records is the column's — not whatever the browser felt like posting.
     *
     * @dataProvider addableTypeProvider
     */
    public function addingAudioToAnEmptySlotPersistsThatColumnsType(
        WebTester $I,
        \Codeception\Example $example,
    ): void {
        $type = (string) $example[0];

        $this->signIn($I);
        // Seed the order with a DIFFERENT recording, so the slot under test is genuinely empty.
        $this->uploadCard($I, self::STORE_A, $type === 'MIXED' ? 'CALLER' : 'MIXED', '16513791');

        $before = count($this->conversationsFor(self::STORE_A));
        $this->replace($I, self::STORE_A, 'order:16513791', $type);

        $rows = $this->conversationsFor(self::STORE_A);
        Assert::assertCount($before + 1, $rows, 'The upload creates its own conversation.');

        $added = $rows[0];
        Assert::assertSame($type, $added['recording_type'], 'The column decides the type.');
        Assert::assertSame('16513791', $added['order_id'], 'It joins the order it was added from.');
        Assert::assertSame('COMMON', $added['mode'], 'CALLER/CALLEE are recording types, never roles.');
    }

    /** @return list<array{string}> */
    protected function addableTypeProvider(): array
    {
        return [['MIXED'], ['CALLER'], ['CALLEE']];
    }

    /**
     * Adding never disturbs what is already there.
     *
     * The replacement flow is additive by construction — a new conversation with a new public id — so
     * the recording already in another column keeps its own job, audio and transcript.
     */
    public function addingAudioLeavesTheExistingRecordingUntouched(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $original = $this->conversationsFor(self::STORE_A)[0];

        $this->replace($I, self::STORE_A, 'order:16513791', 'CALLEE');

        $rows = $this->conversationsFor(self::STORE_A);
        $survivor = null;
        foreach ($rows as $row) {
            if ($row['public_id'] === $original['public_id']) {
                $survivor = $row;
            }
        }

        Assert::assertNotNull($survivor, 'The recording that was already there must still exist.');
        Assert::assertSame('CALLER', $survivor['recording_type'], 'And must still be what it was.');
    }

    /** A tampered type on the add/replace endpoint cannot become a fourth kind of recording. */
    public function aTamperedTypeOnTheAddEndpointIsRefused(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED', '16513791');

        $before = count($this->conversationsFor(self::STORE_A));
        $this->replace($I, self::STORE_A, 'order:16513791', 'PRESIDENT');

        Assert::assertCount(
            $before,
            $this->conversationsFor(self::STORE_A),
            'An unknown recording type must create nothing at all.',
        );
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
        // No order id was posted, so the other two columns cannot offer to add a recording.
        $this->seeRecordingInColumn($I, 'MIXED', canAdd: false);
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

        // Completed first, because there is nothing to put a voice to until there is a transcript, and
        // the control is now withheld until then. This test is about the endpoint's store scoping, so
        // it wants the control present — not the state in which it is absent.
        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($child['public_id']);

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

    // ------------------------------------------------------------------ Manage Audio

    /**
     * The dialog is told what the order holds and what may be done to it.
     *
     * Asserted through the payload rather than the markup, because the payload is the contract: the
     * browser renders exactly what this says and works nothing out for itself — least of all which
     * recording is current.
     */
    public function manageAudioListsEveryRecordingKindAndOffersReplacement(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED', '16513791');
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $payload = $this->recordings($I, self::STORE_A, 'order:16513791');

        Assert::assertTrue($payload['canReplace']);
        Assert::assertNull($payload['reason']);
        Assert::assertSame('16513791', $payload['orderId']);
        Assert::assertCount(3, $payload['slots'], 'Mixed, caller and callee, always.');

        $byType = [];
        foreach ($payload['slots'] as $slot) {
            $byType[$slot['recordingType']] = $slot;
        }

        Assert::assertCount(1, $byType['MIXED']['versions']);
        Assert::assertTrue($byType['MIXED']['versions'][0]['current']);
        // The kind this order does not have yet is still offered, because "there is no callee
        // recording" is exactly what an administrator opens this dialog to fix.
        Assert::assertSame([], $byType['CALLEE']['versions']);
        Assert::assertTrue($byType['CALLEE']['canReplace']);
    }

    /**
     * A replacement is a new recording; nothing about the one it replaces is touched.
     *
     * The assertions are against the database, because that is where the guarantee lives: the old
     * conversation, its job, its retained audio path and its transcript are all still exactly as they
     * were, and the new row is a separate conversation with its own public id.
     */
    public function replacingARecordingAddsAVersionAndLeavesTheOldOneIntact(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $before = $this->conversationsFor(self::STORE_A);
        Assert::assertCount(1, $before);
        $originalJob = $this->childrenOf((int) $before[0]['id'])[0];

        $this->replace($I, self::STORE_A, 'order:16513791', 'CALLER');
        $I->seeResponseCodeIs(200);
        $I->seeInSource('"success":true');

        $after = $this->conversationsFor(self::STORE_A);
        Assert::assertCount(2, $after, 'The replacement is a new conversation, not a rewrite.');
        Assert::assertSame('CALLER', $after[0]['recording_type']);
        Assert::assertSame('16513791', $after[0]['order_id'], 'It joins the same order.');
        Assert::assertNotSame($before[0]['public_id'], $after[0]['public_id']);

        // The recording being replaced, re-read: every column as it was.
        $old = $this->childrenOf((int) $before[0]['id'])[0];
        Assert::assertSame($originalJob['public_id'], $old['public_id']);
        Assert::assertSame($originalJob['retained_audio_path'], $old['retained_audio_path']);
        Assert::assertSame($originalJob['transcript'], $old['transcript']);
        Assert::assertSame($originalJob['reviewed_segments'], $old['reviewed_segments']);

        // And the replacement starts empty: its own job, its own storage, nothing carried across.
        $new = $this->childrenOf((int) $after[0]['id'])[0];
        Assert::assertNotSame($old['public_id'], $new['public_id']);
        Assert::assertNull($new['transcript']);
        Assert::assertNull($new['reviewed_segments']);
    }

    /**
     * Replacing one side does not disturb the other two.
     *
     * The failure this guards against is not subtle in effect — it would silently discard a correct
     * recording — but it is easy to introduce, because "the order's recordings" is one query away from
     * "this order's caller recording".
     */
    public function replacingOneRecordingLeavesTheOtherKindsAlone(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED', '16513791');
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');
        $this->uploadCard($I, self::STORE_A, 'CALLEE', '16513791');

        $before = $this->conversationsFor(self::STORE_A);
        $this->replace($I, self::STORE_A, 'order:16513791', 'CALLER');

        $after = $this->conversationsFor(self::STORE_A);
        Assert::assertCount(4, $after);

        // Every original row is still present, unchanged.
        $stillThere = array_map(static fn(array $r): string => (string) $r['public_id'], $after);
        foreach ($before as $row) {
            Assert::assertContains((string) $row['public_id'], $stillThere);
        }
    }

    /**
     * A replacement never buys audio.
     *
     * `generate_ai_audio` is what the worker reads to queue Deepgram TTS without being asked. An
     * administrator fixing a mistaken upload has not asked for audio to be paid for on the corrected
     * one, so the replacement is written with the flag off — even when the recording it replaces has
     * it on, which this seeds deliberately.
     */
    public function aReplacementNeverInheritsTheAutomaticAiAudioOptIn(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $before = $this->conversationsFor(self::STORE_A);
        $this->connection->createCommand()->update(
            '{{%audio_conversations}}',
            ['generate_ai_audio' => 1],
            ['id' => $before[0]['id']],
        )->execute();

        $this->replace($I, self::STORE_A, 'order:16513791', 'CALLER');

        $after = $this->conversationsFor(self::STORE_A);
        Assert::assertSame(0, (int) $after[0]['generate_ai_audio'], 'No provider spend was requested.');
    }

    /** The provider of the recording being replaced is the one the replacement uses. */
    public function aReplacementKeepsTheProviderOfTheRecordingItReplaces(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $before = $this->conversationsFor(self::STORE_A);
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['transcription_provider' => 'DEEPGRAM'],
            ['conversation_id' => $before[0]['id']],
        )->execute();

        $this->replace($I, self::STORE_A, 'order:16513791', 'CALLER');

        $after = $this->conversationsFor(self::STORE_A);
        $new = $this->childrenOf((int) $after[0]['id'])[0];
        Assert::assertSame('DEEPGRAM', $new['transcription_provider']);
    }

    /** An upload that named no order has nothing for a replacement to join, and says so. */
    public function anOrderlessRecordingExplainsWhyItCannotBeReplaced(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED');

        $conversation = $this->conversationsFor(self::STORE_A)[0]['public_id'];
        $payload = $this->recordings($I, self::STORE_A, 'conversation:' . $conversation);

        Assert::assertFalse($payload['canReplace']);
        Assert::assertStringContainsString('order id', (string) $payload['reason']);

        // And the refusal is a rule, not a hidden button: posting anyway is refused too.
        $this->replace($I, self::STORE_A, 'conversation:' . $conversation, 'MIXED');
        $I->seeResponseCodeIs(422);
        $I->seeInSource('"success":false');
    }

    /** A group belonging to another store is not found, exactly as an invented key is not. */
    public function anotherStoresOrderCannotBeReadOrReplaced(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        // The key is real; the store in the address is not the one that owns it.
        $I->amOnPage('/audio-to-text/store/' . self::STORE_B . '/group/order:16513791/recordings');
        $I->seeResponseCodeIs(404);

        $this->replace($I, self::STORE_B, 'order:16513791', 'CALLER');
        $I->seeResponseCodeIs(404);

        // And nothing was created anywhere by the attempt.
        Assert::assertCount(0, $this->conversationsFor(self::STORE_B));
        Assert::assertCount(1, $this->conversationsFor(self::STORE_A));
    }

    /** Signed out, both endpoints are the login page rather than an answer. */
    public function manageAudioRequiresAnAdministrator(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');
        $I->resetCookie(self::SESSION_COOKIE);

        $I->amOnPage('/audio-to-text/store/' . self::STORE_A . '/group/order:16513791/recordings');
        $I->dontSeeInSource('"slots"');

        $I->sendAjaxPostRequest(
            '/audio-to-text/store/' . self::STORE_A . '/group/order:16513791/replace',
            ['recording_type' => 'CALLER'],
        );
        Assert::assertCount(1, $this->conversationsFor(self::STORE_A), 'Nothing was uploaded.');
    }

    /** Without the token the upload does not happen, exactly as on the page's own form. */
    public function aReplacementWithoutItsTokenIsRefused(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $I->sendAjaxPostRequest(
            '/audio-to-text/store/' . self::STORE_A . '/group/order:16513791/replace',
            ['recording_type' => 'CALLER'],
        );

        Assert::assertCount(1, $this->conversationsFor(self::STORE_A), 'CSRF is still enforced.');
    }

    /** A recording type that is not one of the three is refused before anything is stored. */
    public function aReplacementMustNameARecordingKindThisApplicationHas(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $this->replace($I, self::STORE_A, 'order:16513791', 'CUSTOMER');
        $I->seeResponseCodeIs(422);
        Assert::assertCount(1, $this->conversationsFor(self::STORE_A));
    }

    // ------------------------------------------------------- the replacement's own upload options

    /**
     * The dialog is given the same two choices the page's own upload form offers.
     *
     * Both are the server's answers, not the browser's: which providers exist, which this machine can
     * run, which one this recording starts on, and whether paid audio is configured at all. A browser
     * that worked any of those out for itself would be a second authority over what may run and over
     * what may be paid for.
     */
    public function theReplacementFormIsGivenTheProviderChoicesAndThePaidOptIn(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $payload = $this->recordings($I, self::STORE_A, 'order:16513791');

        Assert::assertArrayHasKey('providers', $payload);
        Assert::assertArrayHasKey('aiAudioConfigured', $payload);

        $values = array_map(static fn(array $p): string => (string) $p['value'], $payload['providers']);
        Assert::assertSame(['WHISPER', 'DEEPGRAM'], $values, 'Every provider, in the order the page lists them.');

        foreach ($payload['providers'] as $provider) {
            Assert::assertArrayHasKey('label', $provider);
            // Listed and marked rather than hidden: an administrator who cannot see the choice cannot
            // tell a one-provider install from a broken one.
            Assert::assertArrayHasKey('usable', $provider);
        }

        // Each slot names the provider its field starts on.
        foreach ($payload['slots'] as $slot) {
            Assert::assertArrayHasKey('provider', $slot);
        }
    }

    /** The field starts on the engine that transcribed the recording being replaced. */
    public function theReplacementProviderDefaultsToTheOneBeingReplaced(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $before = $this->conversationsFor(self::STORE_A);
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['transcription_provider' => 'DEEPGRAM'],
            ['conversation_id' => $before[0]['id']],
        )->execute();

        $payload = $this->recordings($I, self::STORE_A, 'order:16513791');
        $caller = $this->slotOf($payload, 'CALLER');

        Assert::assertSame('DEEPGRAM', $caller['provider']);

        // And a kind this order has no recording of falls back to the server's default rather than
        // to whatever another recording happened to use.
        Assert::assertSame('WHISPER', $this->slotOf($payload, 'CALLEE')['provider']);
    }

    /** The administrator may choose another engine, and that is what the new job records. */
    public function anAdministratorCanOverrideTheReplacementProvider(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $before = $this->conversationsFor(self::STORE_A);
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['transcription_provider' => 'DEEPGRAM'],
            ['conversation_id' => $before[0]['id']],
        )->execute();
        $oldJob = $this->childrenOf((int) $before[0]['id'])[0];

        $this->replace($I, self::STORE_A, 'order:16513791', 'CALLER', [
            'transcription_provider' => 'WHISPER',
        ]);
        $I->seeResponseCodeIs(200);

        $after = $this->conversationsFor(self::STORE_A);
        Assert::assertSame(
            'WHISPER',
            $this->childrenOf((int) $after[0]['id'])[0]['transcription_provider'],
            'The replacement records the engine that was chosen for it.',
        );

        // And the recording it replaces keeps the engine it was actually transcribed with.
        Assert::assertSame(
            'DEEPGRAM',
            $this->childrenOf((int) $before[0]['id'])[0]['transcription_provider'],
        );
        Assert::assertSame($oldJob['public_id'], $this->childrenOf((int) $before[0]['id'])[0]['public_id']);
    }

    /**
     * A provider value the application does not issue is refused, and nothing is stored.
     *
     * The same refusal the page's own upload form gives, because it is the same object giving it —
     * {@see \App\AudioToText\Application\UploadOptions}.
     */
    public function anUnknownReplacementProviderIsRefused(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $this->replace($I, self::STORE_A, 'order:16513791', 'CALLER', [
            'transcription_provider' => 'ACME_TRANSCRIBE',
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeInSource('listed transcription providers');
        Assert::assertCount(1, $this->conversationsFor(self::STORE_A), 'Nothing was queued.');
    }

    /**
     * A real provider this server cannot run is refused too, before anything is stored.
     *
     * Deepgram is unconfigured in this suite's environment, which is what makes this assertable: the
     * refusal is the shared rule's, not a second one written for replacements.
     */
    public function aReplacementProviderThisServerCannotRunIsRefused(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $payload = $this->recordings($I, self::STORE_A, 'order:16513791');
        $deepgram = null;
        foreach ($payload['providers'] as $provider) {
            if ($provider['value'] === 'DEEPGRAM') {
                $deepgram = $provider;
            }
        }

        Assert::assertNotNull($deepgram);

        $this->replace($I, self::STORE_A, 'order:16513791', 'CALLER', [
            'transcription_provider' => 'DEEPGRAM',
        ]);

        if ($deepgram['usable'] === true) {
            // Configured here after all, so the assertable thing is the other half of the same rule:
            // a usable provider is accepted and recorded.
            $I->seeResponseCodeIs(200);
            Assert::assertCount(2, $this->conversationsFor(self::STORE_A));

            return;
        }

        $I->seeResponseCodeIs(422);
        $I->seeInSource('not configured on this server');
        Assert::assertCount(1, $this->conversationsFor(self::STORE_A));
    }

    /**
     * The paid box is off unless this upload ticked it, whatever the recording it replaces asked for.
     *
     * Three cases in one test because they are one rule: the flag is read from this request and is
     * never inherited. Inheriting it would mean an administrator fixing a mistaken upload silently
     * pays for audio a second time, for a request they did not make.
     */
    public function thePaidAudioOptInIsReadFromThisUploadAndNeverInherited(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        // The recording being replaced asked for audio.
        $first = $this->conversationsFor(self::STORE_A);
        $this->connection->createCommand()->update(
            '{{%audio_conversations}}',
            ['generate_ai_audio' => 1],
            ['id' => $first[0]['id']],
        )->execute();

        // 1. Box not posted at all — an unticked checkbox posts nothing.
        $this->replace($I, self::STORE_A, 'order:16513791', 'CALLER');
        $rows = $this->conversationsFor(self::STORE_A);
        Assert::assertSame(0, (int) $rows[0]['generate_ai_audio'], 'Not inherited, and not assumed.');

        // 2. Box ticked — the intent is recorded on this upload.
        $this->replace($I, self::STORE_A, 'order:16513791', 'CALLER', ['generate_ai_audio' => '1']);
        $rows = $this->conversationsFor(self::STORE_A);
        Assert::assertSame(
            $this->ttsIsConfigured($I) ? 1 : 0,
            (int) $rows[0]['generate_ai_audio'],
            'Recorded when asked for, and only when this server could honour it.',
        );

        // 3. The recording being replaced is untouched throughout.
        Assert::assertSame(
            1,
            (int) $this->conversationsFor(self::STORE_A)[2]['generate_ai_audio'],
            'The old version keeps its own answer.',
        );
    }

    /**
     * Ticking the box queues nothing here. It records an intent and returns.
     *
     * The whole cost protection rests on the web tier never generating: a replacement is queued for
     * transcription, and the existing worker — or the speaker confirmation, when the roles are not
     * yet known — is what queues audio afterwards. Asserted as the absence of a rendition row, which
     * is the only thing a synchronous generation could not avoid creating.
     */
    public function tickingThePaidBoxQueuesNoAudioFromTheWebRequest(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $this->replace($I, self::STORE_A, 'order:16513791', 'CALLER', ['generate_ai_audio' => '1']);
        $I->seeResponseCodeIs(200);

        $newJob = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];

        Assert::assertSame('QUEUED', $newJob['status'], 'Queued for transcription, nothing more.');
        Assert::assertSame(
            0,
            (int) (new Query($this->connection))
                ->from('{{%audio_tts_renditions}}')
                ->where(['job_id' => $newJob['id']])
                ->count(),
            'No rendition exists yet: the web tier records the intent and the worker acts on it.',
        );
    }

    /**
     * A replacement is held to the same file rules as a first upload.
     *
     * It was not. This action called the transcription queue directly, and the queue checks only the
     * duration — so a renamed text file, or one over the size limit, was refused on the store page's own
     * form and accepted here. Both paths now go through {@see \App\AudioToText\Application\AudioIngestionService},
     * which validates before it queues, and this is the assertion that says so.
     */
    public function aReplacementIsValidatedLikeAnyOtherUpload(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $before = $this->conversationsFor(self::STORE_A);
        Assert::assertCount(1, $before);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $token = (string) $I->grabAttributeFrom('#a2t-upload-form input[type="hidden"][name="_csrf"]', 'value');

        // Real WAV bytes under a name this application does not accept. Chosen deliberately: a text
        // file would be refused either way, because ffprobe cannot read it — so it would prove nothing
        // about whether the validator ran. ffprobe reads this one happily; only the extension rule
        // refuses it, and that rule lives in the validator alone.
        $this->audioBrowser->_loadPage(
            'POST',
            '/audio-to-text/store/' . self::STORE_A . '/group/order:16513791/replace',
            ['_csrf' => $token, 'recording_type' => 'CALLER'],
            ['audio' => [
                'name' => 'pretend.bin',
                'tmp_name' => codecept_data_dir('kf_store_valid.wav'),
            ]],
        );

        $I->seeResponseCodeIs(422);
        $I->seeInSource('"success":false');
        Assert::assertCount(1, $this->conversationsFor(self::STORE_A), 'Nothing was queued.');
    }

    // ------------------------------------------------------------------- the order-level details modal

    /**
     * The order id is a way in to every recording of the call, and the row carries the channels.
     *
     * Asserted on the markup rather than by clicking, because the markup IS the contract: the modal
     * builds its tabs from this row's own Details buttons, in the order the columns run. Nothing is
     * fetched to discover them, so nothing about the page's cost changes.
     */
    public function theOrderIdOpensEveryRecordingOfTheCall(WebTester $I): void
    {
        $this->signIn($I);
        $this->completedOrder($I, ['MIXED', 'CALLER', 'CALLEE']);

        $I->amOnPage($this->storeUrl(self::STORE_A));

        $I->seeElement('button.a2t-order-id--open[data-a2t-order-open="16513791"]');
        $I->see('#16513791', 'button.a2t-order-id--open');

        // The dialog's tab strip is rendered empty and hidden; the script fills it on open.
        $I->seeElement('#a2t-review-dialog .a2t-tabs[data-a2t-review-tabs][hidden]');

        // Three channels in the row, in column order — which is the priority the modal selects by:
        // Mix / Common, then Customer, then Agent. Marked recordings only: a superseded upload sits in
        // the same cell with its own Details button, and it is not a channel.
        Assert::assertSame(
            ['Mix / Common', 'Customer', 'Agent'],
            $this->channelLabels($I),
        );
    }

    /**
     * Fewer recordings means fewer tabs, and the first one still leads.
     *
     * The default channel is whichever comes first in that order, so an order with no mixed recording
     * opens on Customer without anything having to say so.
     *
     * @dataProvider orderChannelSets
     */
    public function theRowOffersOnlyTheChannelsTheOrderHas(WebTester $I, \Codeception\Example $case): void
    {
        $this->signIn($I);
        $this->completedOrder($I, (array) $case['upload']);

        $I->amOnPage($this->storeUrl(self::STORE_A));

        Assert::assertSame(
            (array) $case['tabs'],
            $this->channelLabels($I),
            'The first of these is the tab the modal opens on.',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function orderChannelSets(): array
    {
        return [
            ['upload' => ['MIXED', 'CALLER', 'CALLEE'], 'tabs' => ['Mix / Common', 'Customer', 'Agent']],
            ['upload' => ['CALLER', 'CALLEE'], 'tabs' => ['Customer', 'Agent']],
            ['upload' => ['CALLEE'], 'tabs' => ['Agent']],
            ['upload' => ['MIXED', 'CALLEE'], 'tabs' => ['Mix / Common', 'Agent']],
            ['upload' => ['CALLER'], 'tabs' => ['Customer']],
        ];
    }

    /**
     * An order with nothing finished is not a way in — it is a row to watch.
     *
     * The details endpoint answers 404 for a recording with nothing to correct, so an order id that
     * opened a dialog on one would open an error. It stays plain text, and Manage Audio remains where
     * a recording still being transcribed is inspected — which is the behaviour that already existed.
     */
    public function anOrderWithNothingFinishedIsNotAWayIn(WebTester $I): void
    {
        $this->signIn($I);
        // Uploaded, never completed: the job stays QUEUED, so nothing in the row is reviewable.
        $this->uploadCard($I, self::STORE_A, 'MIXED', '16513791');

        $I->amOnPage($this->storeUrl(self::STORE_A));

        $I->dontSeeElement('button.a2t-order-id--open');
        $I->see('#16513791', '.a2t-order-id');
        $I->dontSeeElement('.a2t-orders tbody tr:first-child [data-a2t-current-channel]');

        // And the way to watch it is still there.
        $I->seeElement('.a2t-orders tbody tr:first-child [data-a2t-manage]');
    }

    /** A row that named no order has one recording, so its id opens nothing it does not already show. */
    public function arowWithNoOrderIdIsNotAWayIn(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED');

        $job = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($job['public_id']);

        $I->amOnPage($this->storeUrl(self::STORE_A));

        $I->dontSeeElement('button.a2t-order-id--open');
        $I->see('No order');
    }

    /**
     * Each tab points at its OWN recording, and the current version of it.
     *
     * The modal reads whichever url the tab carries, so two tabs pointing at one recording — or at a
     * superseded version of one — would show the same transcript under two names. The urls are the
     * row's, which the repository built from the group's current recordings.
     */
    public function eachChannelPointsAtItsOwnCurrentRecording(WebTester $I): void
    {
        $this->signIn($I);
        $this->completedOrder($I, ['MIXED', 'CALLER']);

        // A second caller upload, completed, which becomes the current caller recording.
        $this->replace($I, self::STORE_A, 'order:16513791', 'CALLER');
        $rows = $this->conversationsFor(self::STORE_A);
        $newest = $this->childrenOf((int) $rows[0]['id'])[0];
        $this->completeWithSeparation($newest['public_id']);

        $I->amOnPage($this->storeUrl(self::STORE_A));

        // The row now holds THREE Details buttons — Mix, the current Customer, and the Customer
        // recording it superseded, which is drawn inside the fold Manage Audio opens.
        Assert::assertCount(
            3,
            $I->grabMultiple('.a2t-orders tbody tr:first-child [data-a2t-details]', 'data-a2t-details'),
            'Arranged as expected: a replacement leaves the recording it replaced in the cell.',
        );

        // Two of them are channels. A side uploaded twice is one tab, not two both called Customer.
        $urls = $I->grabMultiple(
            '.a2t-orders tbody tr:first-child [data-a2t-current-channel]',
            'data-a2t-details',
        );

        Assert::assertSame(['Mix / Common', 'Customer'], $this->channelLabels($I));
        Assert::assertCount(2, $urls);
        Assert::assertSame($urls, array_unique($urls), 'No two tabs may open the same recording.');
        Assert::assertStringContainsString(
            $newest['public_id'],
            $urls[1],
            'The Customer tab opens the newest FINISHED caller recording, not the one it replaced.',
        );
    }

    /**
     * The channel names this row offers, in the order the modal would place its tabs.
     *
     * Marked recordings only — see `data-a2t-current-channel`. Reading every Details button instead
     * would count a superseded upload as a channel, which is the mistake this selector exists to stop.
     *
     * @return list<string>
     */
    private function channelLabels(WebTester $I): array
    {
        return $I->grabMultiple(
            '.a2t-orders tbody tr:first-child [data-a2t-current-channel]',
            'data-a2t-details-label',
        );
    }

    /**
     * A legacy Customer + Agent pair offers two tabs, named for what they are and in that order.
     *
     * The case the tab ordering had to be rewritten for. Both halves render inside the FIRST cell — the
     * one headed Mix / Common — because a pre-recording-type pair belongs in no named column. Ordering
     * the tabs by where the buttons sit would therefore have put both under Mix, in the order their
     * jobs happened to be inserted.
     *
     * So this asserts the three things that could go wrong at once: the names are Customer and Agent
     * and not Mix, Customer leads, and neither appears twice.
     */
    public function alegacyPairOffersCustomerThenAgentAndNeverMix(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadSeparate($I, self::STORE_A);

        foreach ($this->conversationsFor(self::STORE_A) as $conversation) {
            foreach ($this->childrenOf((int) $conversation['id']) as $job) {
                $this->completeWithSeparation($job['public_id']);
            }
        }

        $I->amOnPage($this->storeUrl(self::STORE_A));

        // Both halves really are in the first cell, which is what makes position useless here.
        $I->seeElement('.a2t-orders tbody tr:first-child td:nth-of-type(2) .a2t-legacy');
        Assert::assertCount(
            2,
            $I->grabMultiple(
                '.a2t-orders tbody tr:first-child td:nth-of-type(2) [data-a2t-current-channel]',
                'data-a2t-details',
            ),
            'Arranged as expected: a legacy pair puts both halves in the Mix / Common column.',
        );

        $labels = $this->channelLabels($I);

        Assert::assertSame(
            [SourceRole::Customer->label(), SourceRole::Agent->label()],
            $labels,
            'Customer leads Agent, and neither is named after the column holding them.',
        );
        Assert::assertSame($labels, array_unique($labels), 'Neither name appears twice.');
        Assert::assertNotContains(
            RecordingType::Mixed->label(),
            $labels,
            'A legacy pair has no mixed recording, so it offers no Mix / Common tab.',
        );

        // And the ranks the script sorts on are the channel positions, not 0 and 1 by arrival.
        Assert::assertSame(
            ['1', '2'],
            $this->channelRanks($I),
            'Customer is the second channel and Agent the third, exactly as a typed row would be.',
        );
    }

    /**
     * The channel positions this row published, in the order the tabs would be drawn.
     *
     * @return list<string>
     */
    private function channelRanks(WebTester $I): array
    {
        return $I->grabMultiple(
            '.a2t-orders tbody tr:first-child [data-a2t-current-channel]',
            'data-a2t-current-channel',
        );
    }

    /**
     * One upload per named type for one order, all completed.
     *
     * @param list<string> $types
     */
    private function completedOrder(WebTester $I, array $types): void
    {
        foreach ($types as $type) {
            $this->uploadCard($I, self::STORE_A, $type, '16513791');
        }

        foreach ($this->conversationsFor(self::STORE_A) as $conversation) {
            foreach ($this->childrenOf((int) $conversation['id']) as $job) {
                $this->completeWithSeparation($job['public_id']);
            }
        }
    }

    // ------------------------------------------------------ what a recording type is called on screen

    /**
     * The table's three columns are headed in the client's vocabulary, from the one place it lives.
     *
     * The words are read from the shared map rather than written here, so a later rename is one edit and
     * this test follows it. What is asserted outright is the pair that changed: the columns no longer say
     * Caller and Callee.
     */
    public function theTableColumnsAreHeadedInTheClientsVocabulary(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');
        $I->amOnPage($this->storeUrl(self::STORE_A));

        foreach (RecordingTypeLabels::all() as $label) {
            $I->see($label, '.a2t-orders thead th');
        }

        $I->dontSee('Caller', '.a2t-orders thead th');
        $I->dontSee('Callee', '.a2t-orders thead th');
    }

    /**
     * The Details dialog names the recording in the same words, and the column underneath does not move.
     *
     * Both halves in one test on purpose. A display change that reached the stored value would still
     * show "Customer" on screen and would have refiled the recording, so the page and the row are read
     * together — the label is the client's, the value is the call's.
     *
     * @dataProvider displayedTypes
     */
    public function theDetailsDialogNamesTheRecordingInTheClientsVocabulary(
        WebTester $I,
        \Codeception\Example $case,
    ): void {
        $stored = (string) $case['stored'];

        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, $stored, '16513791');

        $conversation = $this->conversationsFor(self::STORE_A)[0];
        Assert::assertSame($stored, $conversation['recording_type'], 'The column keeps the call\'s word.');

        $job = $this->childrenOf((int) $conversation['id'])[0];
        $this->completeWithSeparation($job['public_id']);

        // The label the row's Details button carries, which is what the dialog's title becomes.
        $I->amOnPage($this->storeUrl(self::STORE_A));
        Assert::assertSame(
            $case['shown'],
            $I->grabAttributeFrom('[data-a2t-details]', 'data-a2t-details-label'),
        );

        // And the dialog's own payload: the Update dialog's read-only type, and the sentence that says
        // whose words these are, both in the same vocabulary.
        $payload = $this->fragment($I, $job['public_id']);
        Assert::assertSame($case['shown'], $payload['replace']['recordingTypeLabel']);
        Assert::assertSame($stored, $payload['replace']['recordingType'], 'The posted value is unchanged.');

        if ($case['voiced']) {
            Assert::assertSame(
                $case['shown'],
                $payload['voice'],
                'The browser composes "This recording is the ... side of the call" from this.',
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function displayedTypes(): array
    {
        return [
            // A mixed recording holds a conversation, so it names no single voice.
            ['stored' => 'MIXED', 'shown' => 'Mix / Common', 'voiced' => false],
            ['stored' => 'CALLER', 'shown' => 'Customer', 'voiced' => true],
            ['stored' => 'CALLEE', 'shown' => 'Agent', 'voiced' => true],
        ];
    }

    /**
     * Adding audio to an empty slot still posts the stored value, whatever the button is called.
     *
     * The button's accessible name is now "Add Customer audio…" while the request it makes still says
     * CALLER. That is the whole shape of this change — the words moved and nothing underneath them did —
     * and it is the one place a careless rename would have swapped the two.
     */
    public function addingAudioStillPostsTheStoredValueNotTheLabel(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED', '16513791');

        // The accessible name, which is where the label reaches a reader on this control — the visible
        // text is the same "+ Add audio" in all three columns.
        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->seeElement('button[aria-label="Add Customer audio for order 16513791"]');
        $I->dontSeeElement('button[aria-label="Add Caller audio for order 16513791"]');

        $this->replace($I, self::STORE_A, 'order:16513791', 'CALLER');
        $I->seeInSource('"success":true');

        Assert::assertSame(
            'CALLER',
            $this->conversationsFor(self::STORE_A)[0]['recording_type'],
            'Displayed as Customer, stored as CALLER.',
        );
    }

    // ---------------------------------------------- Update Audio, from inside the Details dialog

    /**
     * The Details dialog is told how to replace the recording it is open on, whichever side that is.
     *
     * Asserted on the payload because the payload is the contract: the dialog shows the recording type
     * as a fact and posts `replaces`, and the browser never works out which of the three it is looking
     * at. All three types are covered because "the caller recording" and "this order's recordings" are
     * one query apart, and getting them confused would file an update under the wrong side.
     *
     * @dataProvider recordingTypes
     */
    public function theDetailsDialogIsToldHowToUpdateThisRecording(
        WebTester $I,
        \Codeception\Example $example,
    ): void {
        $type = (string) $example[0];
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, $type, '16513791');

        $job = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($job['public_id']);

        $replace = $this->fragment($I, $job['public_id'])['replace'];

        Assert::assertSame($type, $replace['recordingType'], 'The server names the side, not the browser.');
        Assert::assertSame(
            $job['public_id'],
            $replace['replaces'],
            'It replaces THIS recording, which is what the endpoint derives the type from.',
        );
        Assert::assertSame('16513791', $replace['orderId']);
        Assert::assertSame(self::STORE_A, $replace['storeSourceId']);
        // Percent-encoded by the router, exactly as the page's own Manage Audio and Text to Audio URLs
        // are — `generate()` escapes the colon, and the matcher decodes it. Asserted in the form the
        // dialog actually posts to rather than the readable one, because that is what has to work.
        Assert::assertSame(
            '/audio-to-text/store/' . self::STORE_A . '/group/order%3A16513791/replace',
            $replace['url'],
            'The existing additive endpoint, keyed by the group the page already groups by.',
        );
    }

    /**
     * The URL the dialog was handed, posted back verbatim, is accepted.
     *
     * The test above asserts the string; this one closes the loop on it. A percent-encoded group key
     * that the router generated but could not match would fail only here, in the one request an
     * administrator makes — and the readable form works, so nothing else would notice.
     */
    public function anUpdatePostedToTheUrlTheDialogWasGivenIsAccepted(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $job = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($job['public_id']);
        $replace = $this->fragment($I, $job['public_id'])['replace'];

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $token = (string) $I->grabAttributeFrom(
            '#a2t-upload-form input[type="hidden"][name="_csrf"]',
            'value',
        );

        $this->audioBrowser->_loadPage(
            'POST',
            (string) $replace['url'],
            ['_csrf' => $token, 'replaces' => (string) $replace['replaces']],
            ['audio' => [
                'name' => 'kf_store_valid.wav',
                'tmp_name' => codecept_data_dir('kf_store_valid.wav'),
            ]],
        );

        $I->seeResponseCodeIs(200);
        $I->seeInSource('"success":true');

        $after = $this->conversationsFor(self::STORE_A);
        Assert::assertCount(2, $after);
        Assert::assertSame('CALLER', $after[0]['recording_type']);
    }

    /**
     * A recording with no order id is offered no update at all.
     *
     * There is nothing to group a replacement under — the same refusal `ReplaceAction` enforces — and
     * the dialog is told so by a null rather than by being given a URL that would be refused.
     */
    public function aRecordingWithNoOrderIsOfferedNoUpdate(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED');

        $job = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($job['public_id']);

        Assert::assertNull($this->fragment($I, $job['public_id'])['replace']);
    }

    /**
     * The Update dialog has no recording type to choose.
     *
     * The absence is the feature. A dialog opened on the caller recording that offered a type select
     * would let an administrator replace a different side than the one they were reading, and the type
     * they picked would be the one the server used — so the control is not rendered, not posted, and
     * ignored if it arrives anyway (see the two tests below).
     */
    public function theUpdateDialogOffersNoRecordingTypeToChoose(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->storeUrl(self::STORE_A));

        $form = 'dialog#a2t-update-dialog form[data-a2t-update-form]';
        $I->seeElement($form);
        $I->seeElement($form . ' input[type="hidden"][name="replaces"][value=""]');
        $I->seeElement($form . ' input[type="file"][name="audio"][required]');
        $I->seeElement($form . ' input[type="hidden"][name="_csrf"]');

        $I->dontSeeElement($form . ' [name="recording_type"]');
        $I->dontSeeElement($form . ' select[name="recording_type"]');
    }

    /**
     * The type comes from the recording being updated, with nothing in the body saying so.
     *
     * This is the whole of the server-side safety change: before it, the type was whatever the form
     * posted, and the dialog's read-only label was the only thing keeping the two in step.
     */
    public function anUpdateTakesItsTypeFromTheRecordingItNames(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLEE', '16513791');

        $job = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];

        $this->update($I, self::STORE_A, 'order:16513791', $job['public_id']);

        $I->seeResponseCodeIs(200);
        $I->seeInSource('"success":true');

        $after = $this->conversationsFor(self::STORE_A);
        Assert::assertCount(2, $after, 'Additive, exactly as a replacement always was.');
        Assert::assertSame('CALLEE', $after[0]['recording_type'], 'Derived, with no posted type at all.');
        Assert::assertSame('16513791', $after[0]['order_id']);
    }

    /**
     * A posted type that contradicts the recording being updated changes nothing.
     *
     * The tampered request cannot corrupt the mixed recording either — this flow only ever writes a new
     * conversation — but it must not file the callee's replacement under Mix, which is a recording
     * quietly appearing on the wrong side of a call nobody will think to check.
     */
    public function anUpdateIgnoresAPostedTypeThatContradictsWhatItNames(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED', '16513791');
        $this->uploadCard($I, self::STORE_A, 'CALLEE', '16513791');

        $callee = $this->conversationsFor(self::STORE_A)[0];
        Assert::assertSame('CALLEE', $callee['recording_type'], 'Arranged as expected.');
        $calleeJob = $this->childrenOf((int) $callee['id'])[0];

        $mixedBefore = $this->conversationsFor(self::STORE_A)[1];

        $this->update($I, self::STORE_A, 'order:16513791', $calleeJob['public_id'], [
            'recording_type' => 'MIXED',
        ]);

        $I->seeResponseCodeIs(200);

        $after = $this->conversationsFor(self::STORE_A);
        Assert::assertCount(3, $after);
        Assert::assertSame(
            'CALLEE',
            $after[0]['recording_type'],
            'The posted MIXED was ignored in favour of the recording named by `replaces`.',
        );

        // And the recording the tampered type pointed at is untouched, down to its public id.
        $mixedAfter = $this->conversationsFor(self::STORE_A)[2];
        Assert::assertSame($mixedBefore['public_id'], $mixedAfter['public_id']);
        Assert::assertSame($mixedBefore['recording_type'], $mixedAfter['recording_type']);
    }

    /**
     * `replaces` naming a recording from somewhere else is refused, and queues nothing.
     *
     * The id is looked up in the group that was already resolved for this store and order, so a job
     * belonging to another order — or another store — simply is not among its recordings. The lookup is
     * the ownership check.
     */
    public function anUpdateNamingARecordingFromAnotherOrderIsRefused(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513792');

        $stranger = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        Assert::assertSame(2, count($this->conversationsFor(self::STORE_A)), 'Arranged as expected.');

        // Order 16513791's group, naming order 16513792's recording.
        $this->update($I, self::STORE_A, 'order:16513791', $stranger['public_id']);

        $I->seeResponseCodeIs(422);
        $I->seeInSource('"success":false');
        Assert::assertCount(2, $this->conversationsFor(self::STORE_A), 'Nothing was queued.');
    }

    /** An id that is not a recording at all is refused the same way. */
    public function anUpdateNamingNothingAtAllIsRefused(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $this->update($I, self::STORE_A, 'order:16513791', str_repeat('a', 32));

        $I->seeResponseCodeIs(422);
        Assert::assertCount(1, $this->conversationsFor(self::STORE_A), 'Nothing was queued.');
    }

    /**
     * The answer names the NEW recording and where to watch it.
     *
     * The dialog polls the job it just created, not the one it was opened on — a replacement is a new
     * recording with a new public id, and watching the old one would report a recording that finished
     * long ago. The URL is generated by the router rather than assembled in the browser.
     */
    public function anUpdateAnswersWithTheNewRecordingsOwnStatusUrl(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'CALLER', '16513791');

        $before = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];

        $this->update($I, self::STORE_A, 'order:16513791', $before['public_id']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);

        $newJob = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];

        Assert::assertSame($newJob['public_id'], $payload['jobPublicId']);
        Assert::assertNotSame($before['public_id'], $payload['jobPublicId']);
        Assert::assertSame(
            '/audio-to-text/job/' . $newJob['public_id'] . '/status',
            $payload['statusUrl'],
        );

        // And that URL answers, with the stage vocabulary the dialog shows and nothing else.
        $I->amOnPage($payload['statusUrl']);
        $I->seeResponseCodeIs(200);

        /** @var array<string, mixed> $state */
        $state = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);
        // `eta` joins the three enum values: two second counts for the dialog's "usually takes about"
        // line, or null where this recording gives nothing to estimate from. Never a countdown.
        Assert::assertSame(['status', 'stage', 'speakerSeparation', 'eta'], array_keys($state));
        Assert::assertSame('QUEUED', $state['status'], 'Queued only. The web request converted nothing.');
    }

    // ------------------------------------------- Generate / Regenerate, from the Details dialog

    /**
     * A recording with no AI audio is offered a generation, and it is a paid one.
     *
     * `paid` is what the confirmation dialog reads to decide whether its final button does anything.
     */
    public function theDetailsDialogOffersGenerationWhenThereIsNoAiAudioYet(WebTester $I): void
    {
        $this->signIn($I);
        $generated = $this->generatedFor($I, self::STORE_A, 'MIXED');

        if ($generated === null) {
            $I->markTestSkipped('Text to speech is not configured on this machine.');
        }

        Assert::assertTrue($generated['canGenerate']);
        Assert::assertSame('Generate', $generated['buttonLabel']);
        Assert::assertFalse($generated['alreadyCurrent'], 'There is nothing on disk to be current.');
        Assert::assertTrue($generated['paid'], 'Pressing this would reach a provider.');
        Assert::assertSame('NotGenerated', $generated['state']);
    }

    /**
     * Audio that already matches the latest transcript is still OFFERED a regeneration — and told so.
     *
     * This is the behaviour that changed. The button used to be withheld for current audio, which is a
     * correct cost decision expressed as a missing control: an administrator who came to regenerate
     * found nothing and could not tell whether the feature was broken or the audio was fine. It is now
     * offered, and `alreadyCurrent` is what the confirmation dialog uses to say so and to disable its own
     * final button. `paid` stays false, so nothing about the cost decision moved into the browser.
     */
    public function currentAiAudioIsOfferedARegenerationThatWouldSpendNothing(WebTester $I): void
    {
        $this->signIn($I);
        $generated = $this->generatedFor($I, self::STORE_A, 'MIXED');

        if ($generated === null) {
            $I->markTestSkipped('Text to speech is not configured on this machine.');
        }

        // A finished rendition of exactly the transcript the dialog was just rendered from. Its digest
        // is the application's own, read back from the payload rather than recomputed here — a second
        // implementation of that hash is the one thing that would make this test lie.
        $this->completeRendition($this->latestJob(self::STORE_A), (string) $generated['expectedHash']);

        $current = $this->generatedFor($I, self::STORE_A, 'MIXED');

        Assert::assertSame('Ready', $current['state']);
        Assert::assertTrue($current['canGenerate'], 'Offered, which it was not before.');
        Assert::assertSame('Regenerate', $current['buttonLabel']);
        Assert::assertTrue($current['alreadyCurrent'], 'And the dialog is told there is nothing to do.');
        Assert::assertFalse($current['paid'], 'So no paid identical re-run can be reached from here.');
    }

    /**
     * Audio made from an older transcript is a paid regeneration, and says which transcript it would read.
     *
     * The distinction between this and the test above is the entire cost guard: same button, same
     * endpoint, and the only difference is whether the digest still matches.
     */
    public function staleAiAudioIsOfferedAPaidRegeneration(WebTester $I): void
    {
        $this->signIn($I);
        $generated = $this->generatedFor($I, self::STORE_A, 'MIXED');

        if ($generated === null) {
            $I->markTestSkipped('Text to speech is not configured on this machine.');
        }

        // Made from something else entirely: a digest of the right shape that is not this transcript's.
        $this->completeRendition($this->latestJob(self::STORE_A), str_repeat('b', 64));

        $stale = $this->generatedFor($I, self::STORE_A, 'MIXED');

        Assert::assertSame('Stale', $stale['state']);
        Assert::assertTrue($stale['canGenerate']);
        Assert::assertSame('Regenerate', $stale['buttonLabel']);
        Assert::assertFalse($stale['alreadyCurrent']);
        Assert::assertTrue($stale['paid']);
    }

    /**
     * The confirmation says which transcript would be read aloud.
     *
     * The one thing about a generation an administrator cannot see from the button, and the thing that
     * makes a regeneration worth paying for: a corrected transcript wins over the machine's, which is
     * `EffectiveConversationReader`'s rule and is not restated in the browser.
     */
    public function theConfirmationNamesWhichTranscriptWouldBeReadAloud(WebTester $I): void
    {
        $this->signIn($I);
        $generated = $this->generatedFor($I, self::STORE_A, 'MIXED');

        if ($generated === null) {
            $I->markTestSkipped('Text to speech is not configured on this machine.');
        }

        Assert::assertSame('Machine transcript', $generated['transcriptSource']);

        $job = $this->latestJob(self::STORE_A);
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            [
                'reviewed_segments' => json_encode([
                    ['start_ms' => 0, 'end_ms' => 2000, 'speaker' => 'A', 'role' => 'CUSTOMER',
                        'text' => 'CORRECTED BY A HUMAN', 'confidence' => 0.9, 'approx' => false],
                ], JSON_THROW_ON_ERROR),
                'review_count' => 1,
            ],
            ['id' => $job],
        )->execute();

        Assert::assertSame(
            'Reviewed / corrected transcript',
            $this->generatedFor($I, self::STORE_A, 'MIXED')['transcriptSource'],
        );
    }

    /**
     * Every TTS state maps to a control that is still there, and says what is happening.
     *
     * The regression this exists for: the control was drawn on `canGenerate` alone, which is false while
     * a worker holds the job — so pressing Regenerate made the button disappear. The request had worked,
     * but its only visible effect was the loss of the thing that had been pressed.
     *
     * `offered` is now "draw it" and `canGenerate` is "it may be pressed". The pair is asserted for every
     * state, because getting one right and the other wrong is exactly how this came back.
     *
     * @dataProvider generatedStates
     */
    public function everyGeneratedStateKeepsAControlAndNamesIt(WebTester $I, \Codeception\Example $case): void
    {
        $this->signIn($I);
        $first = $this->generatedFor($I, self::STORE_A, 'MIXED');

        if ($first === null) {
            $I->markTestSkipped('Text to speech is not configured on this machine.');
        }

        $hash = $case['current'] ? (string) $first['expectedHash'] : str_repeat('b', 64);

        if ($case['status'] !== null) {
            $this->writeRendition($this->latestJob(self::STORE_A), $case['status'], $hash, $case['file']);
        }

        $generated = $this->fragment(
            $I,
            $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0]['public_id'],
        )['audio']['generated'];

        Assert::assertSame($case['state'], $generated['state'], 'Arranged the state this case is about.');
        Assert::assertTrue($generated['offered'], 'The control is drawn in every one of these states.');
        Assert::assertSame($case['enabled'], $generated['canGenerate'], 'Whether it may be pressed.');
        Assert::assertSame($case['inFlight'], $generated['inFlight']);
        Assert::assertSame($case['label'], $generated['actionLabel']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function generatedStates(): array
    {
        return [
            // No rendition row at all.
            ['status' => null, 'file' => false, 'current' => true, 'state' => 'NotGenerated',
                'enabled' => true, 'inFlight' => false, 'label' => 'Generate AI Audio'],

            ['status' => 'READY', 'file' => true, 'current' => true, 'state' => 'Ready',
                'enabled' => true, 'inFlight' => false, 'label' => 'Regenerate AI Audio'],

            // A finished file made from a transcript that has since changed.
            ['status' => 'READY', 'file' => true, 'current' => false, 'state' => 'Stale',
                'enabled' => true, 'inFlight' => false, 'label' => 'Regenerate AI Audio'],

            // The two a worker holds. Drawn, named, and NOT pressable — this is the whole fix.
            ['status' => 'QUEUED', 'file' => false, 'current' => true, 'state' => 'Queued',
                'enabled' => false, 'inFlight' => true, 'label' => 'Starting…'],
            ['status' => 'GENERATING', 'file' => false, 'current' => true, 'state' => 'Generating',
                'enabled' => false, 'inFlight' => true, 'label' => 'Generating…'],

            // Failed with nothing behind it is a first attempt: there is nothing to RE-generate.
            ['status' => 'FAILED', 'file' => false, 'current' => true, 'state' => 'Failed',
                'enabled' => true, 'inFlight' => false, 'label' => 'Generate AI Audio'],

            // Failed after a regeneration keeps the file it was replacing, so it is a Regenerate.
            ['status' => 'FAILED', 'file' => true, 'current' => true, 'state' => 'Failed',
                'enabled' => true, 'inFlight' => false, 'label' => 'Regenerate AI Audio'],
        ];
    }

    /**
     * A generation cannot be asked for twice while a worker already has it.
     *
     * The disabled button is a courtesy; this is the rule. Asserted at the endpoint, because a disabled
     * control in a browser stops nobody with a second tab open.
     */
    public function asecondGenerationIsRefusedWhileOneIsInFlight(WebTester $I): void
    {
        $this->signIn($I);
        $generated = $this->generatedFor($I, self::STORE_A, 'MIXED');

        if ($generated === null) {
            $I->markTestSkipped('Text to speech is not configured on this machine.');
        }

        $jobId = $this->latestJob(self::STORE_A);
        $this->writeRendition($jobId, 'GENERATING', (string) $generated['expectedHash'], false);

        $before = (int) (new Query($this->connection))
            ->from('{{%audio_tts_renditions}}')
            ->where(['job_id' => $jobId])
            ->count();

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $token = (string) $I->grabAttributeFrom(
            '#a2t-upload-form input[type="hidden"][name="_csrf"]',
            'value',
        );

        $this->audioBrowser->_loadPage('POST', (string) $generated['action'], [
            '_csrf' => $token,
            'output_type' => (string) $generated['outputType'],
            'expected_hash' => (string) $generated['expectedHash'],
        ]);

        Assert::assertSame(
            $before,
            (int) (new Query($this->connection))
                ->from('{{%audio_tts_renditions}}')
                ->where(['job_id' => $jobId])
                ->count(),
            'No second rendition row: the one in flight is the one that finishes.',
        );

        Assert::assertSame(
            'GENERATING',
            (string) (new Query($this->connection))
                ->select('status')
                ->from('{{%audio_tts_renditions}}')
                ->where(['job_id' => $jobId])
                ->scalar(),
            'And the attempt under way was not restarted underneath the worker.',
        );
    }

    /**
     * A recording that can produce nothing is offered no control at all.
     *
     * The other half of `offered`: it is not "always true". A generation in flight is the one state that
     * keeps the control while refusing the press; nothing said on this side of the call keeps neither.
     */
    public function arecordingWithUnpublishedSpeakersIsOfferedNoControl(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED', '16513791');

        $job = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($job['public_id']);

        // Transcribed and readable — the dialog opens and shows the turns — but the speakers were never
        // published, so no voice may be put to them and `TtsGenerationService::isEligible()` says no.
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['speaker_separation_status' => 'PENDING', 'roles_confirmed_at' => null],
            ['public_id' => $job['public_id']],
        )->execute();

        $generated = $this->fragment($I, $job['public_id'])['audio']['generated'];

        Assert::assertFalse($generated['offered'], 'No voice may be assigned, so there is nothing to press.');
        Assert::assertFalse($generated['canGenerate']);
        Assert::assertFalse($generated['inFlight']);
        Assert::assertNotNull($generated['reason'], 'And the panel is told why, since no control says it.');
    }

    /**
     * One cell of a store card's breakdown strip, named by the shared label rather than by a literal.
     *
     * `preg_quote` because the labels are display text and one of them already contains a slash and
     * spaces — a rename to something with a metacharacter in it must not turn this into a broken
     * pattern that silently matches nothing.
     */
    private function breakdownCell(string $storedType, int $count): string
    {
        // The short form, which is what this strip renders — see `eachCardShowsTheRecordingTypeBreakdown`.
        $label = RecordingTypeLabels::shortForStorageValue($storedType);
        Assert::assertNotNull($label, $storedType . ' has no short display label.');

        return '~' . preg_quote($label, '~') . '</dt>\s*<dd[^>]*>' . $count . '</dd>~';
    }

    /** The three sides of a call, as the grouped table names them. */
    protected function recordingTypes(): array
    {
        return [['MIXED'], ['CALLER'], ['CALLEE']];
    }

    /**
     * The Details dialog's payload for one recording.
     *
     * @return array<string, mixed>
     */
    private function fragment(WebTester $I, string $jobPublicId): array
    {
        $I->amOnPage('/audio-to-text/job/' . $jobPublicId . '/review/fragment');
        $I->seeResponseCodeIs(200);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * One completed recording of `$type`, and what its Details dialog says about generating AI audio.
     *
     * Null when this machine has no text-to-speech configured, which is the one state in which the
     * server withholds the whole block — the tests that call this skip rather than assert a control that
     * is correctly absent.
     *
     * @return array<string, mixed>|null
     */
    private function generatedFor(WebTester $I, int $sourceId, string $type): ?array
    {
        if ($this->conversationsFor($sourceId) === []) {
            $this->uploadCard($I, $sourceId, $type, '16513791');
            $this->completeWithSeparation(
                $this->childrenOf((int) $this->conversationsFor($sourceId)[0]['id'])[0]['public_id'],
            );
        }

        $job = $this->childrenOf((int) $this->conversationsFor($sourceId)[0]['id'])[0];

        /** @var array<string, mixed>|null $generated */
        $generated = $this->fragment($I, $job['public_id'])['audio']['generated'];

        return $generated === null || $generated['canGenerate'] === false && $generated['reason'] !== null
            ? null
            : $generated;
    }

    private function latestJob(int $sourceId): int
    {
        return (int) $this->childrenOf((int) $this->conversationsFor($sourceId)[0]['id'])[0]['id'];
    }

    /**
     * A finished rendition on disk, made from `$sourceHash`.
     *
     * Written directly because generating one means paying a provider. The two columns that matter are
     * the status and `file_hash` — the hash of the transcript the FILE was made from, which is what the
     * staleness rule compares against the transcript as it stands now.
     */
    private function completeRendition(int $jobId, string $sourceHash): void
    {
        $this->writeRendition($jobId, 'READY', $sourceHash, true);
    }

    /**
     * One rendition row in whatever state the test is about.
     *
     * `$withFile` is what separates "Failed after a first attempt" from "Failed after a regeneration":
     * the second still has the file it was replacing, and the page calls that Regenerate rather than
     * Generate. Written directly because reaching these states for real means paying a provider.
     */
    private function writeRendition(int $jobId, string $status, string $sourceHash, bool $withFile): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%audio_tts_renditions}}', [
            'job_id' => $jobId,
            'output_type' => 'MIXED',
            'status' => $status,
            'attempt_token' => str_repeat('c', 32),
            'requested_hash' => $sourceHash,
            'file_name' => $withFile ? 'kf_tts_test.mp3' : null,
            'file_hash' => $withFile ? $sourceHash : null,
            // The key the running configuration yields. Anything else is a rendition the page correctly
            // calls "generated with a different voice setting", which is not the state under test.
            'file_render_key' => $withFile
                ? TtsRenderKey::for(TtsRenderSettings::fromParams(), TtsOutputType::Mixed)
                : null,
            'file_bytes' => $withFile ? 1024 : null,
            'provider' => 'DEEPGRAM',
            'created_at' => $now,
            'updated_at' => $now,
            'generated_at' => $withFile ? $now : null,
        ])->execute();
    }

    /**
     * An Update, as the Details dialog sends it: `replaces` and a file, and no recording type.
     *
     * @param array<string, string> $fields anything else the request should carry, including a
     *                                      contradicting `recording_type` for the tampering tests
     */
    private function update(
        WebTester $I,
        int $sourceId,
        string $groupKey,
        string $replaces,
        array $fields = [],
    ): void {
        $I->amOnPage($this->storeUrl($sourceId));
        $token = (string) $I->grabAttributeFrom(
            '#a2t-upload-form input[type="hidden"][name="_csrf"]',
            'value',
        );

        $this->audioBrowser->_loadPage(
            'POST',
            '/audio-to-text/store/' . $sourceId . '/group/' . $groupKey . '/replace',
            ['_csrf' => $token, 'replaces' => $replaces] + $fields,
            ['audio' => [
                'name' => 'kf_store_valid.wav',
                'tmp_name' => codecept_data_dir('kf_store_valid.wav'),
            ]],
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function slotOf(array $payload, string $type): array
    {
        foreach ($payload['slots'] as $slot) {
            if ($slot['recordingType'] === $type) {
                return $slot;
            }
        }

        Assert::fail('No ' . $type . ' slot in the payload.');
    }

    private function ttsIsConfigured(WebTester $I): bool
    {
        return $this->recordings($I, self::STORE_A, 'order:16513791')['aiAudioConfigured'] === true;
    }

    /**
     * @return array<string, mixed>
     */
    private function recordings(WebTester $I, int $sourceId, string $groupKey): array
    {
        $I->amOnPage('/audio-to-text/store/' . $sourceId . '/group/' . $groupKey . '/recordings');

        /** @var array<string, mixed> $payload */
        $payload = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * The replacement upload, carrying its file and the page's token exactly as the dialog does.
     *
     * Posted through the browser module directly, as {@see LegacySeparateAudioUpload} does, because a
     * multipart body with a file is not something `submitForm` can build for a form that only exists
     * once the dialog has fetched.
     */
    /** @param array<string, string> $fields the provider select and the paid box, when they are set */
    private function replace(
        WebTester $I,
        int $sourceId,
        string $groupKey,
        string $type,
        array $fields = [],
    ): void {
        $I->amOnPage($this->storeUrl($sourceId));
        $csrf = '#a2t-upload-form input[type="hidden"][name="_csrf"]';
        $token = (string) $I->grabAttributeFrom($csrf, 'value');

        $this->audioBrowser->_loadPage(
            'POST',
            '/audio-to-text/store/' . $sourceId . '/group/' . $groupKey . '/replace',
            ['_csrf' => $token, 'recording_type' => $type] + $fields,
            ['audio' => [
                'name' => 'kf_store_valid.wav',
                'tmp_name' => codecept_data_dir('kf_store_valid.wav'),
            ]],
        );
    }

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    // ------------------------------------------------------------- confirming before transcription

    /**
     * The confirmation is on the page, and the button opens it rather than starting anything.
     *
     * The server-side half of the guarantee: the control that used to send a POST is now an ordinary
     * button carrying the facts the dialog reads. Nothing about it submits.
     */
    public function theTranscribeButtonOpensAConfirmationRatherThanStarting(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);
        $this->makeReadyForTranscription(self::STORE_A);

        $I->amOnPage($this->storeUrl(self::STORE_A));

        $I->see('Transcribe audio');
        $I->seeElement('#a2t-transcribe-dialog');
        $I->see('Transcribe this audio?');
        $I->see('Start transcription');
        $I->see('Cancel');

        // A button, not a form: there is no submit here that could start work without the dialog.
        $I->seeElement('button[data-a2t-transcribe]');
        $I->dontSeeElement('form[action*="/transcribe"]');
    }

    /** The button carries the facts the dialog names, for its own recording. */
    public function eachTranscribeButtonCarriesItsOwnRecordingsFacts(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED', '16674631');
        $this->makeReadyForTranscription(self::STORE_A);

        $I->amOnPage($this->storeUrl(self::STORE_A));

        $I->seeElement('button[data-a2t-transcribe-order="16674631"]');
        $I->seeElement('button[data-a2t-details-label="Mix / Common"]');
        // The provider the request will actually use, named on the button.
        $I->seeElement('button[data-a2t-transcribe-provider]');
    }

    /**
     * Rendering the page starts nothing.
     *
     * The complement to the JavaScript test that opening the dialog makes no request: here the whole
     * page is loaded and the recording is still waiting to be asked for afterwards.
     */
    public function loadingThePageDoesNotRequestAnyTranscript(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);
        $this->makeReadyForTranscription(self::STORE_A);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->amOnPage($this->storeUrl(self::STORE_A));

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        Assert::assertSame(
            'NOT_REQUESTED',
            $this->jobStatus((string) $child['public_id']),
            'Looking at the page must never start work.',
        );
    }

    /**
     * The endpoint behind the dialog still accepts exactly one ask.
     *
     * The backend idempotency is the final protection — the disabled button only stops the common case.
     * A second POST affects no rows and is refused, whatever the browser does.
     */
    public function theTranscribeEndpointStillAcceptsOnlyOneAsk(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);
        $this->makeReadyForTranscription(self::STORE_A);

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $publicId = (string) $child['public_id'];

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $token = $I->grabAttributeFrom('input[name="_csrf"]', 'value');

        $I->sendAjaxPostRequest('/audio-to-text/job/' . $publicId . '/transcribe', ['_csrf' => $token]);
        $I->seeResponseCodeIs(200);
        Assert::assertSame('QUEUED', $this->jobStatus($publicId));

        // The second ask changes nothing and is refused. This is the final protection: the disabled
        // button only stops the common case, and the browser is not what decides this.
        $I->sendAjaxPostRequest('/audio-to-text/job/' . $publicId . '/transcribe', ['_csrf' => $token]);
        $I->seeResponseCodeIs(409);
        Assert::assertSame('QUEUED', $this->jobStatus($publicId));
    }

    /** And asking for one channel leaves the other two exactly where they were. */
    public function askingForOneChannelDoesNotStartTheOthers(WebTester $I): void
    {
        $this->signIn($I);

        // Three recordings of one call — the row the reader sees, and the reason the per-recording
        // guarantee matters: the three Transcribe buttons sit side by side.
        foreach (['MIXED', 'CALLER', 'CALLEE'] as $type) {
            $this->uploadCard($I, self::STORE_A, $type, '16513791');
        }

        $this->makeReadyForTranscription(self::STORE_A);

        $children = [];

        foreach ($this->conversationsFor(self::STORE_A) as $conversation) {
            foreach ($this->childrenOf((int) $conversation['id']) as $job) {
                $children[] = $job;
            }
        }

        Assert::assertGreaterThan(1, count($children), 'This test needs a call with several recordings.');

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $token = $I->grabAttributeFrom('input[name="_csrf"]', 'value');

        $I->sendAjaxPostRequest(
            '/audio-to-text/job/' . $children[0]['public_id'] . '/transcribe',
            ['_csrf' => $token],
        );

        Assert::assertSame('QUEUED', $this->jobStatus((string) $children[0]['public_id']));

        foreach (array_slice($children, 1) as $other) {
            Assert::assertSame(
                'NOT_REQUESTED',
                $this->jobStatus((string) $other['public_id']),
                'Asking for one recording must never start another.',
            );
        }
    }

    /** Every recording of this store's newest call, back to the state before anybody asked. */
    private function makeReadyForTranscription(int $sourceId): void
    {
        foreach ($this->conversationsFor($sourceId) as $conversation) {
            foreach ($this->childrenOf((int) $conversation['id']) as $job) {
                $this->connection->createCommand()->update(
                    '{{%audio_transcription_jobs}}',
                    ['status' => 'NOT_REQUESTED', 'processing_stage' => 'QUEUED'],
                    ['id' => $job['id']],
                )->execute();
            }
        }
    }

    private function jobStatus(string $publicId): string
    {
        return (string) (new Query($this->connection))
            ->select('status')
            ->from('{{%audio_transcription_jobs}}')
            ->where(['public_id' => $publicId])
            ->scalar();
    }

    // ------------------------------------------------- recordings that have been asked for and not arrived

    /**
     * A call appears here the moment somebody asks for it, before any audio exists.
     *
     * The audio is fetched on a schedule, so for a minute or two there is no conversation, no file and
     * nothing this page was ever able to draw. It showed exactly what it had shown before, which reads
     * as the request not having worked. Now the row is there with its order, its call time and three
     * channels in progress.
     */
    public function aCallAppearsWhileItsRecordingsAreStillBeingDownloaded(WebTester $I): void
    {
        $this->signIn($I);
        $this->askForCall(self::STORE_A, '22635909', '16674631', [
            'mixed' => 'PENDING',
            'caller' => 'PENDING',
            'callee' => 'PENDING',
        ]);

        $I->amOnPage($this->storeUrl(self::STORE_A));

        $I->see('16674631');
        // The provider's own string, printed as sent.
        $I->see('2026-10-01 01:43:12');
        $I->see('Downloading recordings');
        $I->see('Pending download');
        $I->see('0 of 3 recordings checked');
        $I->see('0 recordings available');
    }

    /**
     * And it offers nothing that needs audio, because there is none.
     *
     * The important half of the previous test. A row with no recordings must not offer to play, to open
     * details, or above all to transcribe — a Transcribe control here would send somebody to ask for the
     * text of a file that does not exist.
     */
    public function anArrivingCallOffersNothingThatNeedsAudio(WebTester $I): void
    {
        $this->signIn($I);
        $this->askForCall(self::STORE_A, '22635909', '16674631', [
            'mixed' => 'FETCHING',
            'caller' => 'PENDING',
            'callee' => 'PENDING',
        ]);

        $I->amOnPage($this->storeUrl(self::STORE_A));

        $I->see('Downloading');
        $I->dontSeeElement('[data-a2t-transcribe]');
        $I->dontSeeElement('[data-a2t-play]');
        $I->dontSee('Ready for transcription');
    }

    /**
     * The mixed recording becomes playable while the two sides are still coming.
     *
     * The channels are fetched one after another, so this is the ordinary middle of a download rather
     * than an edge case: one real cell with a player and a Transcribe control, two still in progress.
     */
    public function anArrivedChannelIsPlayableWhileItsSiblingsAreStillComing(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCard($I, self::STORE_A, 'MIXED', '16674631');

        $child = $this->childrenOf((int) $this->conversationsFor(self::STORE_A)[0]['id'])[0];
        $this->completeWithSeparation($child['public_id']);

        // The same order, with the two sides still outstanding.
        $this->askForCall(self::STORE_A, '22635909', '16674631', [
            'mixed' => 'IMPORTED',
            'caller' => 'FETCHING',
            'callee' => 'PENDING',
        ]);

        $I->amOnPage($this->storeUrl(self::STORE_A));

        // The arrived one is a real recording cell, drawn from its conversation.
        $I->seeElement('[data-a2t-details]');

        // The others say where they are, and offer nothing.
        $I->see('Downloading');
        $I->see('Pending download');
        $I->see('1 of 3 recordings checked');
        $I->see('1 recording available');

        // The panel is the transcription card's own structure, not a second design for one idea.
        $I->seeElement('.a2t-processing .a2t-processing__stages');
        $I->seeElement('.a2t-processing__stage[data-state="complete"]');
        $I->seeElement('.a2t-processing__stage[data-state="active"]');
    }

    /** While anything is outstanding the page polls; the moment nothing is, it does not. */
    public function theStorePageOnlyPollsWhileSomethingIsArriving(WebTester $I): void
    {
        $this->signIn($I);
        $this->uploadCommon($I, self::STORE_A);

        $I->amOnPage($this->storeUrl(self::STORE_A));
        $I->dontSeeElement('[data-a2t-arriving-poll]');

        $this->askForCall(self::STORE_A, '22635909', '16674631', ['mixed' => 'PENDING']);
        $I->amOnPage($this->storeUrl(self::STORE_A));

        $I->seeElement('[data-a2t-arriving-poll]');
    }

    /** Settled channels are not reported as arriving, so a finished call stops the polling. */
    public function aFinishedDownloadIsNotReportedAsArriving(WebTester $I): void
    {
        $this->signIn($I);
        $this->askForCall(self::STORE_A, '22635909', '16674631', [
            'mixed' => 'IMPORTED',
            'caller' => 'NOT_AVAILABLE',
            'callee' => 'NOT_AVAILABLE',
        ]);

        $I->amOnPage($this->storeUrl(self::STORE_A));

        // Settled, so the acquisition UI is gone entirely — no panel, no bar, no leftover counts.
        $I->dontSeeElement('[data-a2t-arriving-poll]');
        $I->dontSeeElement('.a2t-arriving');
        $I->dontSee('Downloading recordings');
        $I->dontSee('of 3 recordings checked');
    }

    /** The poll endpoint answers for one store, in counts, and names nothing internal. */
    public function theArrivingEndpointReportsCountsAndNothingElse(WebTester $I): void
    {
        $this->signIn($I);
        $this->askForCall(self::STORE_A, '22635909', '16674631', [
            'mixed' => 'IMPORTED',
            'caller' => 'FETCHING',
            'callee' => 'PENDING',
        ]);

        $I->amOnPage($this->storeUrl(self::STORE_A) . '/arriving');
        $I->seeResponseCodeIs(200);

        /** @var array{calls: array<string, array<string, mixed>>, active: bool} $payload */
        $payload = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);

        Assert::assertTrue($payload['active']);
        Assert::assertSame('1 of 3 recordings checked', $payload['calls']['22635909']['progressText']);
        Assert::assertSame('1 recording available', $payload['calls']['22635909']['availabilityText']);
        Assert::assertSame(1, $payload['calls']['22635909']['available']);
        Assert::assertSame('Downloading', $payload['calls']['22635909']['channels']['caller']['label']);
        Assert::assertSame('active', $payload['calls']['22635909']['channels']['caller']['step']);
        Assert::assertSame('pending', $payload['calls']['22635909']['channels']['callee']['step']);

        // The storage statuses. `step` is presentation vocabulary shared with the transcription
        // panel and is deliberately not treated as a leak.
        foreach (['batch', 'company', 'error', 'IMPORTED', 'FETCHING', 'NOT_AVAILABLE'] as $leak) {
            Assert::assertStringNotContainsStringIgnoringCase(
                $leak,
                $I->grabPageSource(),
                'The poll endpoint publishes something written for a log.',
            );
        }
    }

    /** It will not report another store's downloads. */
    public function theArrivingEndpointIsScopedToItsStore(WebTester $I): void
    {
        $this->signIn($I);
        $this->askForCall(self::STORE_A, '22635909', '16674631', ['mixed' => 'PENDING']);

        $I->amOnPage($this->storeUrl(self::STORE_B) . '/arriving');
        $I->seeResponseCodeIs(200);

        /** @var array{calls: array<string, mixed>, active: bool} $payload */
        $payload = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);

        Assert::assertSame([], $payload['calls']);
        Assert::assertFalse($payload['active']);
    }

    /**
     * Reading the page costs the same whether one call is arriving or twenty.
     *
     * The guard against the obvious way to build this: a lookup per row. The port answers in two
     * statements however many calls it covers, so a page that polls every few seconds stays affordable.
     */
    public function arrivingRecordingsCostAFixedNumberOfQueries(WebTester $I): void
    {
        $this->signIn($I);

        for ($i = 0; $i < 8; $i++) {
            $this->askForCall(
                self::STORE_A,
                '2263590' . $i,
                '1667463' . $i,
                ['mixed' => 'PENDING', 'caller' => 'PENDING', 'callee' => 'PENDING'],
            );
        }

        $before = $this->queryCount();
        $I->amOnPage($this->storeUrl(self::STORE_A) . '/arriving');
        $I->seeResponseCodeIs(200);
        $spent = $this->queryCount() - $before;

        // Two for the port, plus the store lookup and the session. Eight calls must not cost eight more.
        Assert::assertLessThan(
            12,
            $spent,
            'Reading arriving recordings is growing with the number of calls — that is an N+1.',
        );
    }

    /** MySQL's own count of statements run, for the N+1 guard above. */
    private function queryCount(): int
    {
        /** @var array<string, mixed>|null $row */
        $row = (new Query($this->connection))
            ->select(['Value' => 'VARIABLE_VALUE'])
            ->from('performance_schema.session_status')
            ->where(['VARIABLE_NAME' => 'Queries'])
            ->one();

        return $row === null ? 0 : (int) $row['Value'];
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
    /**
     * @param bool $canAdd whether the empty columns may offer "+ Add audio". False for a row with no
     *                     order id, which has nothing to group a second recording under — the same
     *                     refusal `ReplaceAction` enforces server-side.
     */
    private function seeRecordingInColumn(WebTester $I, string $type, bool $canAdd = true): void
    {
        $row = '.a2t-orders tbody tr:first-child';

        foreach (self::TYPE_COLUMN as $candidate => $column) {
            $cell = $row . ' td:nth-child(' . $column . ')';

            if ($candidate === $type) {
                // Queued, so there is no audio to play yet — but the cell is occupied and says so.
                $I->seeElement($cell . ' .a2t-slot');
                $I->dontSeeElement($cell . ' .a2t-slot__add');

                continue;
            }

            $I->dontSeeElement($cell . ' .a2t-slot');

            if ($canAdd) {
                // Nothing was uploaded for this side of the call, so the cell offers to add it —
                // carrying THIS column's own recording type, which is the point of the affordance.
                $I->seeElement($cell . ' .a2t-slot__add[data-a2t-manage-focus="' . $candidate . '"]');
            } else {
                // An em dash, as before: there is nothing here and nothing that could be added.
                $I->seeElement($cell . ' .util-muted');
                $I->dontSeeElement($cell . ' .a2t-slot__add');
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
    /**
     * One call asked for, with each channel in whatever state the test needs.
     *
     * Writes the import rows directly rather than going through the recordings page: this suite is
     * about what the *store* page does with them, and driving the other page here would make these
     * tests fail for its reasons as well as their own.
     *
     * @param array<string, string> $channels storage channel value => storage status
     */
    private function askForCall(
        int $store,
        string $sessionId,
        string $orderId,
        array $channels,
        string $callTime = '2026-10-01 01:43:12',
    ): void {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_call_import_batches}}', [
            'store_source_id' => $store,
            'triggered_by' => 'MANUAL',
            'import_mode' => 'DOWNLOAD_ONLY',
            'requested_by_admin_id' => null,
            'transcription_provider' => 'WHISPER',
            'generate_ai_audio' => 0,
            'recording_company' => 'KFAS',
            'call_count' => 1,
            'created_at' => $now,
        ])->execute();

        $batchId = (int) $this->connection->getLastInsertID();

        foreach ($channels as $channel => $status) {
            $this->connection->createCommand()->insert('{{%order58_call_imports}}', [
                'batch_id' => $batchId,
                'store_source_id' => $store,
                'call_session_id' => $sessionId,
                'channel' => $channel,
                'call_time_raw' => $callTime,
                'call_date' => '2026-10-01',
                'order_id' => $orderId,
                'status' => $status,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
        }
    }

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

        // The import rows this suite's arriving-recording tests create, and the batches they hang off.
        // Children before parents: the batch foreign key is RESTRICT.
        foreach ([self::STORE_A, self::STORE_B, self::STORE_C] as $sourceId) {
            $this->connection->createCommand()
                ->delete('{{%order58_call_imports}}', ['store_source_id' => $sourceId])
                ->execute();
            $this->connection->createCommand()
                ->delete('{{%order58_call_import_batches}}', ['store_source_id' => $sourceId])
                ->execute();
        }

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
