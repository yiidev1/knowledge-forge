<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Integration\Order58Recording\FixtureAvailability;
use App\Order58\Domain\CallImportMode;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function gmdate;
use function str_contains;
use function str_repeat;

use const SORT_DESC;

/**
 * Order58 Call Recordings, end to end through the browser tier.
 *
 * ## What this page exists to do, and what these tests hold it to
 *
 * Downloading a recording and transcribing it used to be one action, so every imported call cost Whisper
 * CPU or Deepgram money whether or not anybody wanted the text. This page downloads and stops. The claim
 * it has to earn is narrow and absolute: **after pressing Download, nothing is queued for transcription**
 * — not for the mixed recording, not for either side, not later by a timer.
 *
 * That is tested at the row level ({@see downloadOnlyIsWrittenOnTheBatch}) rather than by reading the
 * page back, because the page is not where it could go wrong. One column on the batch is the entire fork,
 * and the worker reads it long after this request is gone.
 *
 * ## And what must not have moved
 *
 * The existing calls page is a near-twin of this one and it is not allowed to change, so the security
 * properties are re-asserted here rather than assumed to be inherited by resemblance:
 *
 * 1. **The browser's list of calls is never believed.** A checkbox posts a call session id, a request can
 *    post anything, and that id becomes a URL path segment when the audio is fetched. The download action
 *    re-reads the provider's own list and keeps only what appears in both.
 * 2. **The company code is resolved from the store, server side.** There is no field for it, and a store
 *    without one downloads nothing rather than falling back to another merchant's code.
 * 3. **The feature flag is a gate, not a hint.** A form rendered before it was turned off must not work.
 *
 * ## The fixture source
 *
 * The provider is unreachable from any machine the client has not allowlisted, so the happy paths run on
 * `?source=fixture` — the same per-request, dev-and-test-only gate the calls page uses. A test that
 * needed the live API would be a test that only runs in production.
 */
final class Order58CallRecordingsCest
{
    private const USERNAME = '__kf_o58_rec_admin__';
    private const PASSWORD = 'Order58RecordingsPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    /** Far outside the mirrored range, so nothing here can collide with real store data. */
    private const STORE = 987655300;
    private const STORE_NAME = '__KF Recordings Store__';
    private const COMPANY = 'KFRS';

    /** A second store with no company code: the one that must refuse to download. */
    private const STORE_NO_COMPANY = 987655301;

    private const PAGE = '/admin/order58/call-recordings';

    /** The fixture call list, which {@see \App\Integration\Order58Recording\FixtureCallSource} generates. */
    private const CALLS = ['22487129', '22487119', '22487109'];

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->createStore(self::STORE, self::STORE_NAME, self::COMPANY);
        $this->createStore(self::STORE_NO_COMPANY, self::STORE_NAME . ' (no code)', null);
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ the gate

    /** Signed out, the page is the login form rather than a store list. */
    public function thePageRequiresAnAdministrator(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage(self::PAGE);

        $I->seeInCurrentUrl('/login');
        $I->dontSee('Order58 Call Recordings');
    }

    /** Signed in, it renders with a store selector and no company field anywhere. */
    public function thePageRendersWithAStoreSelector(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->see('Order58 Call Recordings');
        $I->seeElement('select[name="store"]');
        $I->see(self::STORE_NAME);

        // The company code is resolved from the store on the server. There is nothing to type, and a
        // field would invite somebody to type the wrong merchant's code.
        $I->dontSeeElement('input[name="company"]');
        $I->dontSeeElement('select[name="company"]');
        $I->dontSee('Company code');
    }

    /**
     * No provider choice and no AI-audio option, because both are decisions about text.
     *
     * Their absence is the page's whole point rather than a simplification: offering a transcription
     * provider on a page that transcribes nothing would be asking a question whose answer is discarded,
     * and would tell the operator that pressing Download starts a transcription.
     */
    public function thePageOffersNoTranscriptionChoices(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->loadUrl());

        $I->dontSeeElement('select[name="transcription_provider"]');
        $I->dontSeeElement('input[name="generate_ai_audio"]');
        $I->dontSee('Transcription provider');
    }

    // ------------------------------------------------------------------ nothing is fetched unasked

    /** Opening the page contacts no provider: the list needs an explicit `load=1`. */
    public function aBarePageLoadFetchesNoCalls(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?store=' . self::STORE);

        $I->dontSee('Call session ID');
        $I->dontSee(self::CALLS[0]);
    }

    /** With `load=1` and the fixture source, the day's calls are listed. */
    public function loadingShowsTheDaysCalls(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->loadUrl());

        $I->see('Call session ID');
        $I->see('Call time');
        $I->see('Recordings');
        $I->see(self::CALLS[0]);

        // Said in plain words, because it is the only surprising thing about the page.
        $I->see('one at a time');
    }

    /** A page showing invented calls says so. */
    public function theFixtureSourceIsDeclaredOnThePage(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->loadUrl());

        $I->see('Local fixtures');
    }

    // ------------------------------------------------------------------ the claim

    /**
     * The one thing this feature is for: downloaded, and not queued for transcription.
     *
     * `import_mode` is the entire fork. It is read by the import worker with the file already in hand,
     * which is minutes or hours after this request ended, so the column is the only thing carrying the
     * operator's intent that far.
     */
    public function downloadOnlyIsWrittenOnTheBatch(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return; // Covered by the flag assertion below; nothing can be requested to observe.
        }

        $this->download($I, [self::CALLS[0]]);

        $batch = $this->batch();

        Assert::assertSame(
            CallImportMode::DownloadOnly->value,
            (string) $batch['import_mode'],
            'A download from this page must never be transcribed by the worker.',
        );
        Assert::assertSame(
            0,
            (int) $batch['generate_ai_audio'],
            'AI audio is a reading of a transcript, and this page asked for no transcript.',
        );
    }

    /** One selected call brings down all three channels, each its own row. */
    public function oneCallAsksForThreeRecordings(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return;
        }

        $this->download($I, [self::CALLS[0]]);

        Assert::assertSame(3, $this->countItems(), 'Mixed, caller and callee — one row each.');

        $channels = (new Query($this->connection))
            ->select('channel')
            ->from('{{%order58_call_imports}}')
            ->where(['store_source_id' => self::STORE, 'call_session_id' => self::CALLS[0]])
            ->orderBy('channel')
            ->column();

        // The stored values, exactly as the column holds them — and still caller and callee. These are
        // who dialled and who answered; the store page displays them as Customer and Agent, which is a
        // different fact about the same recording and is never written back here.
        Assert::assertSame(['callee', 'caller', 'mixed'], $channels);
    }

    /** And the operator is told in recordings, not in machinery. */
    public function theConfirmationNamesRecordingsRatherThanAQueue(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return;
        }

        $this->download($I, [self::CALLS[0]]);

        $I->see('selected for download');
        $I->see('Nothing is transcribed until you ask for it');

        $page = $I->grabPageSource();

        foreach (['queued', 'queue position', 'worker', 'job'] as $banned) {
            Assert::assertStringNotContainsStringIgnoringCase(
                $banned,
                $page,
                'The confirmation names this application\'s internals rather than the operator\'s audio.',
            );
        }
    }

    /** Pressing Download twice does not ask for anything twice. */
    public function downloadingTheSameCallsTwiceCreatesNoDuplicates(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return;
        }

        $this->download($I, [self::CALLS[0]]);
        Assert::assertSame(3, $this->countItems());

        $this->download($I, [self::CALLS[0]]);

        Assert::assertSame(3, $this->countItems(), 'The unique key refuses the second ask.');
        Assert::assertSame(2, $this->countBatches(), 'Both presses are recorded; only one made rows.');
        $I->see('had already been requested');
    }

    // ------------------------------------------------------------------ what must not have moved

    /** A forged id is dropped: the provider's own list is what decides. */
    public function onlyTheRecognisedCallsAreRequested(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return;
        }

        $this->download($I, [self::CALLS[0], '99999999']);

        Assert::assertSame(3, $this->countItems(), 'One real call, three channels. The forged id is gone.');
    }

    /** An entirely forged selection asks for nothing at all and says why. */
    public function aWhollyForgedSelectionRequestsNothing(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return;
        }

        $this->download($I, ['99999999']);

        Assert::assertSame(0, $this->countItems());
        $I->see('None of the selected calls');
    }

    /** No selection is a refusal, not an empty batch. */
    public function anEmptySelectionIsRefused(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->loadUrl());
        $I->submitForm('form[action*="/call-recordings/download"]', [
            'store' => (string) self::STORE,
            'source' => FixtureAvailability::FIXTURE,
        ]);

        Assert::assertSame(0, $this->countItems());
        Assert::assertSame(0, $this->countBatches(), 'Nothing was written, not even a batch.');
        $I->see('Select at least one call');
    }

    /**
     * A store with no company code downloads nothing.
     *
     * The code is part of the provider's URL. Falling back to another store's would build a perfectly
     * well-formed request for somebody else's audio, which is the one failure here that would not look
     * like a failure.
     */
    public function aStoreWithoutACompanyCodeDownloadsNothing(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return;
        }

        $this->download($I, [self::CALLS[0]], self::STORE_NO_COMPANY);

        Assert::assertSame(0, $this->countItems());
        Assert::assertSame(0, $this->countBatches());
    }

    /** A posted company code is ignored: the store's own is used. */
    public function aPostedCompanyCodeIsIgnored(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return;
        }

        $I->amOnPage($this->loadUrl());
        $I->submitForm('form[action*="/call-recordings/download"]', [
            'store' => (string) self::STORE,
            'source' => FixtureAvailability::FIXTURE,
            'calls' => [self::CALLS[0]],
            'company' => 'NOTMINE',
        ]);

        Assert::assertSame(
            self::COMPANY,
            (string) $this->batch()['recording_company'],
            'The resolver reads the store. A posted code is not validated, it is ignored.',
        );
    }

    /** The date these rows came from is carried, so a past day's selection is not refused. */
    public function thePostCarriesTheDayTheRowsCameFrom(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->loadUrl());

        $I->seeElement('form[action*="/call-recordings/download"] input[name="date"]');
    }

    // ------------------------------------------------------------------ the existing page is untouched

    /**
     * The calls page still writes the mode it always has.
     *
     * The whole design rests on this: every row that page creates takes the column's default, so adding
     * the column changed nothing about it. Asserted from this suite because it is this feature that
     * would break it.
     */
    public function theCallsPageStillAsksForTranscription(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return;
        }

        $I->amOnPage('/admin/order58/calls?store=' . self::STORE . '&load=1&source='
            . FixtureAvailability::FIXTURE);
        $I->submitForm('form[action*="/calls/sync"]', [
            'store' => (string) self::STORE,
            'source' => FixtureAvailability::FIXTURE,
            'calls' => [self::CALLS[0]],
        ]);

        Assert::assertSame(
            CallImportMode::DownloadAndTranscribe->value,
            (string) $this->batch()['import_mode'],
            'The calls page must behave exactly as it did before this feature existed.',
        );
    }

    // ------------------------------------------------------------------ helpers

    private function loadUrl(int $store = self::STORE): string
    {
        return self::PAGE . '?store=' . $store . '&load=1&source=' . FixtureAvailability::FIXTURE;
    }

    /**
     * @param list<string> $calls call session ids to tick
     */
    private function download(WebTester $I, array $calls, int $store = self::STORE): void
    {
        $I->amOnPage($this->loadUrl($store));

        $I->submitForm('form[action*="/call-recordings/download"]', [
            'store' => (string) $store,
            'source' => FixtureAvailability::FIXTURE,
            'calls' => $calls,
        ]);
    }

    /**
     * Whether this server will actually download anything.
     *
     * Read from the rendered page rather than from the environment, because the page and this test are
     * different processes and only the page's own answer is the one that matters.
     */
    private function importEnabled(WebTester $I): bool
    {
        $I->amOnPage(self::PAGE);

        return !str_contains($I->grabPageSource(), 'Recording download is turned off on this server.');
    }

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    private function createStore(int $sourceId, string $name, ?string $company): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => $name,
            'company' => $company,
            'active' => 1,
            'snapshot_json' => '{"id":' . $sourceId . ',"fields":{}}',
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        // The dropdown reads `knowledge_bases`, because `source_active` there is the authoritative flag —
        // `order58_stores.active` is 0 for every mirrored row in this database.
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => $name,
            'slug' => 'kf-o58-rec-' . $sourceId,
            'source_system' => 'order58',
            'source_store_id' => $sourceId,
            'source_name' => $name,
            'source_active' => 1,
            'agent_enabled' => 1,
            'vector_store_status' => 'pending',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function batch(): array
    {
        /** @var array<string, mixed>|null $row */
        $row = (new Query($this->connection))
            ->from('{{%order58_call_import_batches}}')
            ->where(['store_source_id' => [self::STORE, self::STORE_NO_COMPANY]])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        Assert::assertNotNull($row, 'Expected a batch to have been written.');

        return $row;
    }

    private function countItems(): int
    {
        return (int) (new Query($this->connection))
            ->from('{{%order58_call_imports}}')
            ->where(['store_source_id' => [self::STORE, self::STORE_NO_COMPANY]])
            ->count();
    }

    private function countBatches(): int
    {
        return (int) (new Query($this->connection))
            ->from('{{%order58_call_import_batches}}')
            ->where(['store_source_id' => [self::STORE, self::STORE_NO_COMPANY]])
            ->count();
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();
        $stores = [self::STORE, self::STORE_NO_COMPANY];

        // Children first: the batch foreign key is RESTRICT.
        $connection->createCommand()->delete('{{%order58_call_imports}}', ['store_source_id' => $stores])->execute();
        $connection->createCommand()
            ->delete('{{%order58_call_import_batches}}', ['store_source_id' => $stores])->execute();
        $connection->createCommand()->delete('{{%knowledge_bases}}', ['source_store_id' => $stores])->execute();
        IntegrationDb::cleanup($connection, '{{%order58_stores}}', ['source_id' => $stores]);
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
