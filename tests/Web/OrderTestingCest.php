<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Shared\Audio\RecordingTypeLabels;
use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function dirname;
use function fclose;
use function file_get_contents;
use function flock;
use function fopen;
use function gmdate;
use function urlencode;
use function str_repeat;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * Order Testing: a second administrative surface over the recordings Audio-to-Text already holds.
 *
 * What these tests are actually protecting is the **boundary**. The two surfaces share a domain on
 * purpose — one set of conversations, jobs, transcripts, renditions and workers — and share no
 * presentation at all. The failure worth catching is either half of that drifting: a page that starts
 * writing its own audio records, or a page that starts sending its operator to the other surface.
 *
 * The audio page's own suite is unchanged and still passes; nothing here asserts anything about it
 * except that it still answers.
 */
final class OrderTestingCest
{
    private const ADMIN = '__kf_order_testing_admin__';
    private const PASSWORD = 'OrderTestingPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    private const STORE_ID = 987655900;
    private const STORE_NAME = 'Order Testing Fixture Store';

    /**
     * A second store that never gets a recording, and the only way to prove the landing filter.
     *
     * The two names share the prefix below and neither contains the other, so one search puts both on
     * one page and `see(STORE_NAME)` cannot be satisfied by the silent one standing in for it.
     */
    private const SILENT_STORE_ID = 987655901;
    private const SILENT_STORE_NAME = 'Order Testing Fixture Silent Shop';
    private const BOTH_STORES = 'Order Testing Fixture';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->createStore();
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ it is admin-only, like the rest

    /** No public Order Testing route. The landing page is behind the same gate as everything else. */
    public function aGuestIsSentToLoginFromTheLanding(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/order-testing');
        $I->seeCurrentUrlEquals('/login');
    }

    /** And so is a store's own page, which is the one that carries an upload form. */
    public function aGuestIsSentToLoginFromAStorePage(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $I->seeCurrentUrlEquals('/login');
    }

    /**
     * There is no agent route into it either.
     *
     * The agent realm is a different authentication entirely, and Order Testing was not given one. A
     * guessable `/agent/...` address answering would be the quietest possible way for that to change.
     */
    public function theAgentRealmHasNoOrderTestingAddress(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);

        foreach (['/agent/order-testing', '/agent/order-testing/store/' . self::STORE_ID] as $address) {
            $I->amOnPage($address);
            $I->seeResponseCodeIs(404);
        }
    }

    // ------------------------------------------------------------------------------------ the menu

    /**
     * The entry sits directly under Audio to Text, and the audio entry is still there.
     *
     * Asserted as an ordered list rather than two `see` calls: "directly under" is the requirement, and
     * two independent assertions would pass with the entries in either order.
     */
    public function theAdminMenuOffersOrderTestingUnderAudioToText(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/');

        $labels = $I->grabMultiple('.sidebar a');
        $labels = array_values(array_filter(array_map('trim', $labels), static fn(string $l): bool => $l !== ''));

        $audio = null;

        foreach ($labels as $index => $label) {
            if (str_contains($label, 'Audio to Text')) {
                $audio = $index;

                break;
            }
        }

        Assert::assertNotNull($audio, 'The Audio to Text entry must still exist: ' . implode(' | ', $labels));
        Assert::assertStringContainsString(
            'Order Testing',
            $labels[$audio + 1] ?? '',
            'Order Testing belongs directly under Audio to Text. Menu was: ' . implode(' | ', $labels),
        );
    }

    // --------------------------------------------------------------------------------- the surface

    /**
     * The landing is the store picker, not a list.
     *
     * The first attempt at this page was a three-column table, which rendered the same stores and threw
     * away everything that makes the picker usable: the search, the filters, the alphabet index and the
     * per-channel counts on each card. Asserted control by control, because "looks the same" is not
     * something a test can check and these are the parts that were missing.
     */
    public function theLandingIsTheStorePickerWithItsFilters(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        $I->amOnPage('/order-testing');
        $I->seeResponseCodeIs(200);

        // Search.
        $I->seeElement('input[name="q"]');
        $I->see('Search');

        // Every filter chip, by the words on it. Asserted by label rather than by href because the url
        // builder omits a filter that is on its default — "All sources" is the bare address, with no
        // `status=` in it at all — so a href assertion would be testing the builder, not the control.
        foreach (['All sources', 'Source active', 'Source inactive', 'All stores', 'Uploaded audio'] as $chip) {
            $I->see($chip, '.filter-chip');
        }

        // And the two that do carry a query really carry it. Scoped to the filter bar, because the
        // sidebar's own Audio to Text entry carries `?audio=with` and an unscoped selector would be
        // satisfied by the menu rather than by the control under test.
        $I->seeElement('.filter-bar a[href*="status=inactive"]');
        $I->seeElement('.filter-bar a[href*="audio=all"]');

        // The alphabet index.
        $I->seeElement('a[href*="letter="]');

        // The card itself, on a page narrowed to it. The unfiltered first page is the real directory in
        // alphabetical order — several hundred stores on this instance — so looking for a fixture there
        // would be asserting where it sorts, not that the card renders.
        $I->amOnPage('/order-testing?q=' . urlencode(self::STORE_NAME));

        $I->see(self::STORE_NAME);
        $I->see('Store #' . self::STORE_ID);

        // The per-channel counts, which is what the table this replaced had no room for. The card uses
        // the SHORT labels — the same map, shortened — so this reads them the way the card does rather
        // than hard-coding four words that a client rename would leave behind.
        foreach (['MIXED', 'CALLER', 'CALLEE'] as $stored) {
            $I->see(RecordingTypeLabels::shortForStorageValue($stored) ?? $stored);
        }

        // And it links on to Order Testing's own store page.
        $I->seeElement('a[href="/order-testing/store/' . self::STORE_ID . '"]');
    }

    /** The simplified table it replaced is gone. */
    public function theLandingIsNotTheOldTable(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        $I->amOnPage('/order-testing');

        $I->dontSeeElement('table.ot-stores');
        $I->dontSee('Stores that hold call recordings. Pick one to work through its orders.');
    }

    /** The filters query, rather than merely render: a search that matches nothing shows nothing. */
    public function theSearchFiltersTheStores(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        $I->amOnPage('/order-testing?q=' . urlencode(self::STORE_NAME));
        $I->see(self::STORE_NAME);

        $I->amOnPage('/order-testing?q=' . urlencode('__no_store_is_called_this__'));
        $I->dontSee(self::STORE_NAME);
    }

    /** The alphabet index narrows to a letter, and a different letter excludes this store. */
    public function theAlphabetIndexFiltersTheStores(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        // The fixture store's name begins with "O". Searched as well as lettered, for the same reason:
        // the letter bucket is the real directory's and holds more than this one store.
        $search = '&q=' . urlencode(self::STORE_NAME);

        $I->amOnPage('/order-testing?letter=o' . $search);
        $I->see(self::STORE_NAME);

        $I->amOnPage('/order-testing?letter=z' . $search);
        $I->dontSee(self::STORE_NAME);
    }

    /** The uploaded-audio filter keeps a store that has recordings and drops one that has none. */
    public function theUploadedAudioFilterNarrowsToStoresWithRecordings(WebTester $I): void
    {
        $this->signIn($I);

        // Narrowed to the fixture by name throughout, so what is being tested is the audio filter and
        // not where this store happens to sort among the directory's several hundred.
        $search = '&q=' . urlencode(self::STORE_NAME);

        // Nothing uploaded yet, so this store is not one with audio.
        $I->amOnPage('/order-testing?audio=with' . $search);
        $I->dontSee(self::STORE_NAME);

        $this->seedRecording();

        $I->amOnPage('/order-testing?audio=with' . $search);
        $I->see(self::STORE_NAME);
    }

    // ------------------------------------------------------- the landing filter, which is the whole page

    /**
     * A bare `/order-testing` lands on Uploaded audio, in the chips and in the query alike.
     *
     * Marking the chip active in the template while still asking for every store would look right and
     * be wrong, so both halves are asserted here: the chip, and the store that has nothing uploaded
     * being absent from the result it describes.
     */
    public function theLandingDefaultsToUploadedAudio(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        $I->amOnPage('/order-testing');
        $I->seeResponseCodeIs(200);

        // The chips.
        $I->see('All sources', 'a.filter-chip--active');
        $I->see('Uploaded audio', 'a.filter-chip--active');
        $I->dontSee('All stores', 'a.filter-chip--active');

        // And the builder agrees with the parser: the chip that is already the landing value generates
        // the bare address, and the one that widens the list spells itself out. The reverse — a bare
        // "All stores" link — is the bug this arrangement exists to prevent, because the action would
        // read it straight back as Uploaded audio and the click would appear to do nothing.
        $I->dontSeeElement('.filter-bar a[href*="audio=with"]');
        $I->seeElement('.filter-bar a[href*="audio=all"]');
    }

    /** The default is a query, not a decoration: a store with no recordings is not in the result. */
    public function theDefaultResultSetExcludesStoresWithoutAudio(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        // Both fixtures on one page, so this is about the audio axis and not about where either store
        // sorts among the directory's several hundred.
        $both = '?q=' . urlencode(self::BOTH_STORES);

        $I->amOnPage('/order-testing' . $both);
        $I->see(self::STORE_NAME);
        $I->dontSee(self::SILENT_STORE_NAME);

        // Explicitly asked for, the widened list holds both.
        $I->amOnPage('/order-testing' . $both . '&audio=all');
        $I->see(self::STORE_NAME);
        $I->see(self::SILENT_STORE_NAME);
        $I->see('All stores', 'a.filter-chip--active');
        $I->dontSee('Uploaded audio', 'a.filter-chip--active');

        // And asked for by name, it narrows exactly as the bare address did.
        $I->amOnPage('/order-testing' . $both . '&audio=with');
        $I->see(self::STORE_NAME);
        $I->dontSee(self::SILENT_STORE_NAME);
        $I->see('Uploaded audio', 'a.filter-chip--active');
    }

    /**
     * The landing result set is the audio picker's, under the filter its own menu entry carries.
     *
     * The two pages reach it differently — one from the menu, one from its own default — and this is
     * the assertion that they arrive at the same place. Membership, not markup: the audio surface has
     * its own template and is not being compared to this one.
     */
    public function theLandingMatchesTheAudioPickersUploadedAudioSemantics(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        $both = '?q=' . urlencode(self::BOTH_STORES);

        $I->amOnPage('/admin/order58/store-audio' . $both . '&audio=with');
        $I->see(self::STORE_NAME);
        $I->dontSee(self::SILENT_STORE_NAME);

        $I->amOnPage('/order-testing' . $both);
        $I->see(self::STORE_NAME);
        $I->dontSee(self::SILENT_STORE_NAME);
    }

    /** The audio picker is untouched by all of this: bare, it still shows every store. */
    public function theAudioPickerStillLandsNeutral(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        $both = '?q=' . urlencode(self::BOTH_STORES);

        $I->amOnPage('/admin/order58/store-audio' . $both);
        $I->see(self::STORE_NAME);
        $I->see(self::SILENT_STORE_NAME);
        $I->see('All stores', 'a.filter-chip--active');
        $I->dontSee('Uploaded audio', 'a.filter-chip--active');
    }

    /** The source axis still works, and still works alongside the landing audio filter. */
    public function theSourceFiltersWorkUnderTheDefaultAudioFilter(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        $both = '&q=' . urlencode(self::BOTH_STORES);

        // The fixture is source-active, so it survives the active filter and is dropped by inactive --
        // with the audio filter still on its landing value in both cases.
        $I->amOnPage('/order-testing?status=active' . $both);
        $I->see(self::STORE_NAME);
        $I->see('Source active', 'a.filter-chip--active');
        $I->see('Uploaded audio', 'a.filter-chip--active');

        $I->amOnPage('/order-testing?status=inactive' . $both);
        $I->dontSee(self::STORE_NAME);
    }

    /**
     * Every other control carries the landing filter with it.
     *
     * A GET form submits only its own fields and a filter link rebuilds the whole query string, so
     * searching or lettering from the landing page is exactly where a default silently reverts to
     * everything. The silent store is the detector: it may not reappear through any of them.
     */
    public function theLandingFilterSurvivesSearchAndLetter(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        $both = urlencode(self::BOTH_STORES);

        // Lettered.
        $I->amOnPage('/order-testing?letter=o&q=' . $both);
        $I->see(self::STORE_NAME);
        $I->dontSee(self::SILENT_STORE_NAME);

        // Searched through the form itself, which is what carries the hidden fields.
        $I->amOnPage('/order-testing');
        $I->submitForm('form.dir-search', ['q' => self::BOTH_STORES]);
        $I->see(self::STORE_NAME);
        $I->dontSee(self::SILENT_STORE_NAME);
        $I->see('Uploaded audio', 'a.filter-chip--active');
    }

    // ------------------------------------------------------------------ sync demo orders, by hand

    /**
     * The picker offers a manual demo-order sync, as a POST.
     *
     * A POST because it does work. Demo-order import is manual by decision — nothing runs it on a
     * timer — so this button IS the synchronisation, and without it seeing a demo order would mean
     * shell access an administrator testing the workflow does not have.
     */
    public function thePickerOffersAManualDemoOrderSync(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage('/order-testing');

        $I->see('Sync demo orders');
        $I->seeElement('form[action="/order-testing/sync-demo-orders"][method="post"]');
        // The CSRF token is in the form, like every other state change here.
        $I->seeElement('form[action="/order-testing/sync-demo-orders"] input[name="_csrf"]');
        // The generic double-submit guard admin.js binds to.
        $I->seeElement('form[action="/order-testing/sync-demo-orders"] button[data-busy-label]');
    }

    /** Pressing it runs the import, says what happened, and leaves the operator on the picker. */
    public function syncingDemoOrdersReportsBackOnThePicker(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage('/order-testing');
        $I->submitForm('form[action="/order-testing/sync-demo-orders"]', []);

        // Post/Redirect/Get: a refresh must not replay the sync.
        $I->seeCurrentUrlEquals('/order-testing');
        // One of the two honest answers. Which one depends on what this machine's demo directory
        // holds, and asserting on a specific count here would be asserting about the filesystem.
        $I->see('Demo orders');
        $I->dontSee('Demo order sync is already running');
    }

    /**
     * A sync while the lock is held says so rather than queueing or importing twice.
     *
     * The lock is taken here on the same file the service uses, which is exactly what a running
     * `kf:order-testing:import-demo-orders` looks like to this request.
     */
    public function syncingWhileTheImporterRunsSaysSo(WebTester $I): void
    {
        $this->signIn($I);

        $lockFile = dirname(__DIR__, 2) . '/runtime/order-testing-import.lock';
        $holder = fopen($lockFile, 'c');
        Assert::assertNotFalse($holder);
        Assert::assertTrue(flock($holder, LOCK_EX | LOCK_NB), 'the lock must be free before this test');

        try {
            $I->amOnPage('/order-testing');
            $I->submitForm('form[action="/order-testing/sync-demo-orders"]', []);

            $I->seeCurrentUrlEquals('/order-testing');
            $I->see('Demo order sync is already running');
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }
    }

    /** It is admin-only, like everything else on this surface. */
    public function aGuestCannotSyncDemoOrders(WebTester $I): void
    {
        // A page first, so the browser has history for the assertion below to read.
        $I->amOnPage('/login');

        // POST, not GET: the route only answers POST, so a GET would be refused by the router before
        // the admin gate was ever consulted — which would make this test pass without proving it.
        $I->sendAjaxPostRequest('/order-testing/sync-demo-orders', []);

        // Bounced to the login form rather than having run anything.
        $I->seeCurrentUrlEquals('/login');
        $I->seeElement('form input[name="username"]');
    }

    /**
     * The sync never shells out.
     *
     * It calls the same application service the console command calls. A web request that can run a
     * shell command is a liability regardless of how carefully the arguments are built — and there
     * are no arguments here to build.
     */
    public function theSyncNeverShellsOutToTheConsole(WebTester $I): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/OrderTesting/Web/Sync/Action.php',
        );

        foreach (['shell_exec', 'exec(', 'system(', 'passthru', 'proc_open', 'popen'] as $forbidden) {
            Assert::assertStringNotContainsString($forbidden, $source, 'the sync action must not ' . $forbidden);
        }
    }

    /** Saving the shared transcription setting from this picker returns here, not to the audio one. */
    public function theTranscriptionSettingFormPostsBackIntoOrderTesting(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        $I->amOnPage('/order-testing');
        $I->seeElement('form[action="/order-testing/settings/default-provider"]');
    }

    /** The store page renders, and names the store the way the audio page does. */
    public function theStorePageRenders(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $I->seeResponseCodeIs(200);
        $I->see(self::STORE_NAME);
        $I->see('Store #' . self::STORE_ID);
    }

    /**
     * Every address the page offers stays inside Order Testing, except the shared job-scoped ones.
     *
     * This is the requirement that keeps the surfaces apart in practice: an operator working through
     * orders must not be moved onto the audio page by opening a transcript or posting a replacement.
     * Job-scoped addresses — status, files, review — are deliberately shared and are allowed through.
     */
    public function theStorePageNeverLinksIntoTheAudioSurface(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $source = $I->grabPageSource();

        preg_match_all('~/audio-to-text/[a-z0-9/_\-{}]+~i', $source, $matches);

        $leaked = array_values(array_unique(array_filter(
            $matches[0],
            // A job's own address is shared on purpose and is not a surface: it is reached by public id,
            // several of them serve files, and a second copy would be a second thing to keep correct.
            static fn(string $url): bool => !str_starts_with($url, '/audio-to-text/job/'),
        )));

        Assert::assertSame(
            [],
            $leaked,
            "Order Testing must not send a reader to the audio surface:\n" . implode("\n", $leaked),
        );
    }

    /** Manage Audio posts its replacement back to Order Testing, not to the audio page. */
    public function manageAudioPostsBackIntoOrderTesting(WebTester $I): void
    {
        $this->signIn($I);
        $conversationId = $this->seedRecording();

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/group/conversation:' . $conversationId . '/recordings',
        );
        $I->seeResponseCodeIs(200);

        $payload = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);

        Assert::assertIsArray($payload);
        Assert::assertStringStartsWith(
            '/order-testing/store/' . self::STORE_ID . '/group/',
            (string) $payload['action'],
            'The replacement upload must come back to the surface it was started from.',
        );
    }

    // ------------------------------------------------------- the audio surface is entirely unaffected

    /** The original addresses still answer, and still say what they always said. */
    public function theAudioSurfaceStillWorks(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        $I->amOnPage('/audio-to-text/store/' . self::STORE_ID);
        $I->seeResponseCodeIs(200);
        $I->see(self::STORE_NAME);
        $I->see('Call recordings for this store, grouped by the order they belong to.');
    }

    /** And its Manage Audio still posts to its own address, unchanged by the seam added for the other. */
    public function theAudioSurfaceManageAudioIsUnchanged(WebTester $I): void
    {
        $this->signIn($I);
        $conversationId = $this->seedRecording();

        $I->amOnPage(
            '/audio-to-text/store/' . self::STORE_ID . '/group/conversation:' . $conversationId . '/recordings',
        );

        $payload = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);

        Assert::assertStringStartsWith(
            '/audio-to-text/store/' . self::STORE_ID . '/group/',
            (string) $payload['action'],
        );
    }

    /**
     * Viewing either surface creates no audio records.
     *
     * Order Testing reads the recordings Audio-to-Text already holds; it does not own a second copy of
     * them. A page that started writing rows merely by being opened would be the first symptom of that
     * having been forgotten.
     */
    public function viewingEitherSurfaceWritesNoAudioRecords(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedRecording();

        $before = $this->audioRowCounts();

        $I->amOnPage('/order-testing');
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $I->amOnPage('/audio-to-text/store/' . self::STORE_ID);

        Assert::assertSame($before, $this->audioRowCounts(), 'Reading a page must write nothing.');
    }

    /** Store isolation still holds: another store's group is not reachable from this store's address. */
    public function aGroupOfAnotherStoreIsNotReachable(WebTester $I): void
    {
        $this->signIn($I);
        $conversationId = $this->seedRecording();

        $I->amOnPage(
            '/order-testing/store/' . (self::STORE_ID + 1) . '/group/conversation:' . $conversationId . '/recordings',
        );
        $I->seeResponseCodeIs(404);
    }

    // -------------------------------------------------------------------------------------- helpers

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    /** @return array<string, int> */
    private function audioRowCounts(): array
    {
        $counts = [];

        foreach (['{{%audio_conversations}}', '{{%audio_transcription_jobs}}', '{{%audio_tts_renditions}}'] as $table) {
            $counts[$table] = (int) (new Query($this->connection))->from($table)->count();
        }

        return $counts;
    }

    /** One mixed recording for this store, written straight in — no worker and no provider involved. */
    private function seedRecording(): string
    {
        $now = gmdate('Y-m-d H:i:s');
        $conversationId = str_repeat('a', 32);
        $adminId = $this->adminId();

        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => $conversationId,
            'store_source_id' => self::STORE_ID,
            'mode' => 'COMMON',
            'recording_type' => 'MIXED',
            'order_id' => null,
            'generate_ai_audio' => 0,
            'uploaded_by_admin_id' => $adminId,
            'created_at' => $now,
        ])->execute();

        $conversationRowId = (int) (new Query($this->connection))
            ->select('id')
            ->from('{{%audio_conversations}}')
            ->where(['public_id' => $conversationId])
            ->scalar();

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => str_repeat('b', 32),
            'conversation_id' => $conversationRowId,
            'uploaded_by_admin_id' => $adminId,
            'original_filename' => 'order-testing.wav',
            'source_role' => 'COMMON',
            'status' => 'NOT_REQUESTED',
            'processing_stage' => 'QUEUED',
            'transcription_provider' => 'WHISPER',
            'duration_seconds' => 12.5,
            'review_count' => 0,
            'created_at' => $now,
        ])->execute();

        return $conversationId;
    }

    private function adminId(): int
    {
        return (int) (new Query($this->connection))
            ->select('id')
            ->from('{{%admin_users}}')
            ->where(['username' => self::ADMIN])
            ->scalar();
    }

    private function createStore(): void
    {
        // Both stores are source-active and alike in every way the directory can see. The only
        // difference is that one of them never gets a recording, which is what makes it the control.
        $this->insertStore(self::STORE_ID, self::STORE_NAME);
        $this->insertStore(self::SILENT_STORE_ID, self::SILENT_STORE_NAME);
    }

    private function insertStore(int $sourceId, string $name): void
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

        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => $name,
            'slug' => 'kf-order-testing-' . $sourceId,
            'source_system' => 'order58',
            'source_store_id' => $sourceId,
            'source_name' => $name,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** Scoped to this suite's own administrator and its own store, never a blanket delete. */
    private function cleanup(): void
    {
        $adminIds = (new Query($this->connection))
            ->select('id')
            ->from('{{%admin_users}}')
            ->where(['username' => self::ADMIN])
            ->column();

        if ($adminIds !== []) {
            // Jobs before conversations before administrators: every one of those foreign keys is
            // RESTRICT, which is deliberate and shapes the order here.
            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['uploaded_by_admin_id' => $adminIds])->execute();
            $this->connection->createCommand()
                ->delete('{{%audio_conversations}}', ['uploaded_by_admin_id' => $adminIds])->execute();
            $this->connection->createCommand()
                ->delete('{{%admin_users}}', ['id' => $adminIds])->execute();
        }

        $stores = [self::STORE_ID, self::SILENT_STORE_ID];

        $this->connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['source_store_id' => $stores])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => $stores])->execute();
    }
}
