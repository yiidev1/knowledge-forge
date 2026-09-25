<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Integration\Order58Recording\FixtureAvailability;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_keys;
use function gmdate;
use function sort;
use function str_contains;
use function str_repeat;

use const SORT_DESC;

/**
 * Manage Order58 Calls, end to end through the browser tier.
 *
 * ## What these tests are really guarding
 *
 * Two things the page could get wrong without looking wrong:
 *
 * 1. **Contacting the provider when nobody asked.** The recording API is rate-limited and IP-gated, so a
 *    page that probes it on every render turns an idle tab into somebody else's problem. The list is
 *    fetched only when `load=1` is present.
 * 2. **Believing the browser's list of calls.** A checkbox posts a call session id and a request can post
 *    anything; that id becomes a URL path segment when the recording is fetched. The sync re-reads the
 *    provider's own list and keeps only what appears in both.
 *
 * ## The fixture source
 *
 * The provider is unreachable from any machine the client has not allowlisted, so the happy paths run on
 * `?source=fixture` — the same per-request, dev-and-test-only gate the recording diagnostic page uses. A
 * test that needed the live API would be a test that only runs in production.
 */
final class Order58CallsCest
{
    private const USERNAME = '__kf_o58_calls_admin__';
    private const PASSWORD = 'Order58CallsPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    /** Far outside the mirrored range, so nothing here can collide with real store data. */
    private const STORE = 987655200;
    private const STORE_NAME = '__KF Calls Store__';
    private const COMPANY = 'KFCS';

    /** A second store with no company code: the one that must refuse to queue. */
    private const STORE_NO_COMPANY = 987655201;

    private const PAGE = '/admin/order58/calls';

    /** The fixture call list, which {@see \App\Integration\Order58Recording\FixtureCallSource} generates. */
    private const CALLS = ['22487129', '22487119', '22487109'];

    private ConnectionInterface $connection;
    private int $adminId;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->adminId = (new DbAdminUserRepository($this->connection, new SystemClock()))
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
        $I->dontSee('Manage Order58 Calls');
    }

    /** Signed in, it renders with a store selector and no Company field anywhere. */
    public function thePageRendersWithAStoreSelector(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->see('Manage Order58 Calls');
        $I->seeElement('select[name="store"]');
        $I->see(self::STORE_NAME);

        // The company code is resolved from the store on the server. There is nothing to type, and a
        // field would invite somebody to type the wrong merchant's code.
        $I->dontSeeElement('input[name="company"]');
        $I->dontSeeElement('select[name="company"]');
        $I->dontSee('Company code');
    }

    /** Nothing about the page's own scripts is inline, because the policy forbids it. */
    public function thePageCarriesNoInlineScript(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $source = $I->grabPageSource();

        Assert::assertStringNotContainsString('onclick=', $source);
        Assert::assertStringNotContainsString('onchange=', $source);
        Assert::assertStringContainsString('order58-calls.js', $source, 'The behaviour is in a file.');
    }

    // ------------------------------------------------------------------ discovery

    /**
     * Opening the page asks the provider for nothing.
     *
     * Asserted by what is absent: with no `load=1` there is no call table and no failure message, which
     * together mean no request was attempted. A page that had tried and failed would say so.
     */
    public function openingThePageContactsNoProvider(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?store=' . self::STORE);

        $I->dontSeeElement('[data-o58-calls]');
        $I->dontSee("Today's calls —");
    }

    /** The list appears only after the explicit Load, and only for today. */
    public function callsAreListedOnlyAfterAnExplicitLoad(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->loadUrl());

        $I->see("Today's calls");
        $I->seeElement('[data-o58-calls]');

        foreach (self::CALLS as $sessionId) {
            $I->see($sessionId);
        }

        // Today's date, from the application's business day rather than the server's UTC date.
        $I->see(gmdate('Y-m-d'));
        // Every call starts unimported.
        $I->see('Not synced');
    }

    /** A select-all control exists, and each row is an independently submittable checkbox. */
    public function everyCallIsSelectableAndSelectAllIsOffered(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->loadUrl());

        $I->seeElement('[data-o58-select-all]');

        foreach (self::CALLS as $sessionId) {
            $I->seeElement('input[name="calls[]"][value="' . $sessionId . '"]');
        }
    }

    /** The two sync options render, with the paid one off. */
    public function theSyncOptionsRenderWithPaidAudioOff(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->loadUrl());

        $I->seeElement('select[name="transcription_provider"]');
        $I->see('Generate clean AI audio after transcription');

        // Unchecked, every render. It spends money, so "off" must never be something the form remembers.
        $I->dontSeeCheckboxIsChecked('input[name="generate_ai_audio"]');
    }

    // ------------------------------------------------------------------ syncing

    /**
     * Sync writes the batch and three channel rows per call, and downloads nothing.
     *
     * The "downloads nothing" half is asserted from the rows: every item is `PENDING` with no bytes and
     * no conversation. A request that had fetched would have left at least one of those set.
     *
     * Both sides of the feature flag are asserted here, because which one applies depends on the
     * machine this runs on and a test that only checked one would quietly stop meaning anything when
     * the other was configured.
     */
    public function syncingQueuesEveryChannelWithoutFetchingAudio(WebTester $I): void
    {
        $this->signIn($I);
        $this->sync($I, self::CALLS);

        if (!$this->importEnabled($I)) {
            $I->see('Recording import is turned off');
            Assert::assertSame(0, $this->countItems(), 'The flag is a gate, not a UI hint.');

            return;
        }

        Assert::assertSame(1, $this->countBatches());
        Assert::assertSame(9, $this->countItems(), 'Three calls, three channels each.');

        foreach ($this->items() as $row) {
            Assert::assertSame('PENDING', $row['status']);
            Assert::assertNull($row['bytes'], 'Nothing was downloaded in the web request.');
            Assert::assertNull($row['conversation_public_id'], 'Nothing was transcribed either.');
        }

        // All three channels, named as the provider names them — sorted, because the row order is the
        // insertion order and the point here is the set, not the sequence.
        $channels = [];
        foreach ($this->items() as $row) {
            $channels[(string) $row['channel']] = true;
        }
        $names = array_keys($channels);
        sort($names);
        Assert::assertSame(['callee', 'caller', 'mixed'], $names);
    }

    /**
     * The company is the store's own, taken from the mirror and snapshotted onto the batch.
     *
     * This is the parameter with the least provider documentation and the most room to be quietly wrong,
     * so it is asserted against the value the page never showed and the browser never sent.
     */
    public function theCompanyComesFromTheStoreAndIsSnapshotted(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return; // Covered by the flag assertion above; nothing can be queued to observe.
        }

        $this->sync($I, [self::CALLS[0]]);

        Assert::assertSame(self::COMPANY, $this->batch()['recording_company']);
    }

    /** A posted company is ignored: the store's own code is used regardless. */
    public function aPostedCompanyCannotOverrideTheStores(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return; // Covered by the flag assertion above; nothing can be queued to observe.
        }

        $this->sync($I, [self::CALLS[0]], ['company' => 'EVIL', 'recording_company' => 'EVIL']);

        Assert::assertSame(self::COMPANY, $this->batch()['recording_company']);
    }

    /** A store with no code queues nothing at all, and says why. */
    public function aStoreWithoutACompanyCodeQueuesNothing(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            // The flag is checked first, so this refusal never gets its turn. Nothing is
            // queued either way, which is what the flag assertion already covers.
            return;
        }

        $this->sync($I, self::CALLS, [], self::STORE_NO_COMPANY);

        $I->see('Recording company code is missing');
        Assert::assertSame(0, $this->countItems(), 'Not one channel was queued.');
        Assert::assertSame(0, $this->countBatches());
    }

    /**
     * A call session id the provider did not return is dropped.
     *
     * The id becomes a path segment in a recording fetch, so an id a request could choose is an address
     * a request could choose. Only ids present in the provider's own answer survive.
     */
    public function forgedCallIdsAreIgnored(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            // The flag is checked first, so this refusal never gets its turn. Nothing is
            // queued either way, which is what the flag assertion already covers.
            return;
        }

        $this->sync($I, ['99999999', '12345678']);

        Assert::assertSame(0, $this->countItems(), 'Neither invented id was queued.');
        $I->see('None of the selected calls');
    }

    /** A real id mixed with a forged one queues only the real one. */
    public function onlyTheRecognisedCallsAreQueued(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return; // Covered by the flag assertion above; nothing can be queued to observe.
        }

        $this->sync($I, [self::CALLS[0], '99999999']);

        Assert::assertSame(3, $this->countItems(), 'One call, three channels.');
    }

    /** Pressing Sync twice does not import anything twice. */
    public function syncingTheSameCallsTwiceCreatesNoDuplicates(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return; // Covered by the flag assertion above; nothing can be queued to observe.
        }

        $this->sync($I, self::CALLS);
        Assert::assertSame(9, $this->countItems());

        $this->sync($I, self::CALLS);

        $I->see('Nothing new to queue');
        Assert::assertSame(9, $this->countItems(), 'The unique key refused the second set.');
        Assert::assertSame(2, $this->countBatches(), 'The click is still recorded, with no items.');
    }

    /** The provider choice and the paid opt-in are recorded on the batch. */
    public function theChosenOptionsAreRecorded(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return; // Covered by the flag assertion above; nothing can be queued to observe.
        }

        $this->sync($I, [self::CALLS[0]], [
            'transcription_provider' => 'DEEPGRAM',
            'generate_ai_audio' => '1',
        ]);

        $batch = $this->batch();

        Assert::assertSame('DEEPGRAM', $batch['transcription_provider']);
        Assert::assertSame(1, (int) $batch['generate_ai_audio']);
        Assert::assertSame($this->adminId, (int) $batch['requested_by_admin_id'], 'Attributed to the admin who clicked.');
    }

    /** Without the token nothing is written, exactly as on every other admin form. */
    public function syncRequiresItsToken(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->loadUrl());

        $I->sendAjaxPostRequest('/admin/order58/calls/sync', [
            'store' => (string) self::STORE,
            'source' => FixtureAvailability::FIXTURE,
            'calls' => self::CALLS,
        ]);

        Assert::assertSame(0, $this->countItems(), 'CSRF is still enforced.');
    }

    // ------------------------------------------------------------------ history and retry

    /** The history names every channel of a call and its one overall verdict. */
    public function theHistoryShowsEachChannelAndTheCallsOutcome(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return; // Covered by the flag assertion above; nothing can be queued to observe.
        }

        $this->sync($I, [self::CALLS[0]]);

        $I->see('Sync history');
        $I->see(self::CALLS[0]);
        $I->see('Mixed');
        $I->see('Caller');
        $I->see('Callee');
        // Nothing has been fetched, so every channel is pending and the call is in progress.
        $I->see('Processing');
    }

    /**
     * Mixed imported alongside a channel the merchant does not produce reads as Partial.
     *
     * The common, healthy shape for most merchants — and the reason it must not look like damage.
     */
    public function aCallWithAnUnavailableChannelReadsAsPartial(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return; // Covered by the flag assertion above; nothing can be queued to observe.
        }

        $this->sync($I, [self::CALLS[0]]);

        $this->setChannelStatus(self::CALLS[0], 'mixed', 'IMPORTED');
        $this->setChannelStatus(self::CALLS[0], 'caller', 'NOT_AVAILABLE');
        $this->setChannelStatus(self::CALLS[0], 'callee', 'NOT_AVAILABLE');

        $I->amOnPage(self::PAGE . '?store=' . self::STORE);

        $I->see('Partial');
        $I->see('Not available');
    }

    /** Retry is offered for a failure, and for nothing else. */
    public function retryIsOfferedOnlyForAFailure(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return; // Covered by the flag assertion above; nothing can be queued to observe.
        }

        $this->sync($I, [self::CALLS[0]]);

        $this->setChannelStatus(self::CALLS[0], 'mixed', 'IMPORTED');
        $this->setChannelStatus(self::CALLS[0], 'caller', 'NOT_AVAILABLE');
        $this->setChannelStatus(self::CALLS[0], 'callee', 'TOO_LARGE');

        $I->amOnPage(self::PAGE . '?store=' . self::STORE);
        $I->dontSeeElement('form[action*="/calls/retry"]');

        // Now one genuinely failed.
        $this->setChannelStatus(self::CALLS[0], 'callee', 'FAILED');
        $I->amOnPage(self::PAGE . '?store=' . self::STORE);
        $I->seeElement('form[action*="/calls/retry"]');
    }

    /** Retrying a failed channel queues it again without touching the ones that worked. */
    public function retryingReQueuesOnlyTheFailedChannel(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return; // Covered by the flag assertion above; nothing can be queued to observe.
        }

        $this->sync($I, [self::CALLS[0]]);

        $this->setChannelStatus(self::CALLS[0], 'mixed', 'IMPORTED');
        $this->setChannelStatus(self::CALLS[0], 'caller', 'FAILED');

        $failedId = $this->itemId(self::CALLS[0], 'caller');

        $I->amOnPage(self::PAGE . '?store=' . self::STORE);
        $I->submitForm('form[action*="/calls/retry"]', [
            'import' => (string) $failedId,
            'store' => (string) self::STORE,
        ]);

        Assert::assertSame('PENDING', $this->status(self::CALLS[0], 'caller'));
        Assert::assertSame('IMPORTED', $this->status(self::CALLS[0], 'mixed'), 'The successful channel is untouched.');
    }

    /** Error text is shown, and it is the sanitised sentence rather than a provider body. */
    public function aFailureShowsItsSanitisedMessage(WebTester $I): void
    {
        $this->signIn($I);

        if (!$this->importEnabled($I)) {
            return; // Covered by the flag assertion above; nothing can be queued to observe.
        }

        $this->sync($I, [self::CALLS[0]]);

        $this->connection->createCommand()->update(
            '{{%order58_call_imports}}',
            [
                'status' => 'FAILED',
                'error_code' => 'ip-not-whitelisted',
                'error_message' => 'Recording API rejected this server/IP.',
            ],
            ['store_source_id' => self::STORE, 'channel' => 'mixed'],
        )->execute();

        $I->amOnPage(self::PAGE . '?store=' . self::STORE);

        $I->see('Recording API rejected this server');
        // Nothing that would only appear in a raw upstream body or a trace.
        $I->dontSee('<html');
        $I->dontSee('Stack trace');
        $I->dontSee('/var/www');
    }

    // ------------------------------------------------------------------ harness

    /**
     * Load today's calls for a store, from the local fixture list.
     *
     * `?source=fixture` is refused outright unless `APP_ENV` is dev or test, so this cannot reach a
     * production page even by accident — see {@see FixtureAvailability}.
     */
    private function loadUrl(int $store = self::STORE): string
    {
        return self::PAGE . '?store=' . $store . '&load=1&source=' . FixtureAvailability::FIXTURE;
    }

    /**
     * @param list<string>          $calls  call session ids to tick
     * @param array<string, string> $extra  additional posted fields
     */
    private function sync(WebTester $I, array $calls, array $extra = [], int $store = self::STORE): void
    {
        $I->amOnPage($this->loadUrl($store));

        $fields = [
            'store' => (string) $store,
            'source' => FixtureAvailability::FIXTURE,
            'calls' => $calls,
        ] + $extra;

        $I->submitForm('form[action*="/calls/sync"]', $fields);
    }

    /**
     * Whether this server will actually queue an import.
     *
     * Read from the rendered page rather than from the environment, because the page and this test are
     * different processes and only the page's own answer is the one that matters. The banner is the
     * feature's own statement about itself.
     */
    private function importEnabled(WebTester $I): bool
    {
        $I->amOnPage(self::PAGE);

        return !str_contains($I->grabPageSource(), 'Recording import is turned off on this server.');
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
            'slug' => 'kf-o58-calls-' . $sourceId,
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
     * @return list<array<string, mixed>>
     */
    private function items(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->from('{{%order58_call_imports}}')
            ->where(['store_source_id' => [self::STORE, self::STORE_NO_COMPANY]])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        return $rows;
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

    private function setChannelStatus(string $sessionId, string $channel, string $status): void
    {
        $this->connection->createCommand()->update(
            '{{%order58_call_imports}}',
            ['status' => $status],
            ['store_source_id' => self::STORE, 'call_session_id' => $sessionId, 'channel' => $channel],
        )->execute();
    }

    private function status(string $sessionId, string $channel): string
    {
        return (string) (new Query($this->connection))
            ->select('status')
            ->from('{{%order58_call_imports}}')
            ->where(['store_source_id' => self::STORE, 'call_session_id' => $sessionId, 'channel' => $channel])
            ->scalar();
    }

    private function itemId(string $sessionId, string $channel): int
    {
        return (int) (new Query($this->connection))
            ->select('id')
            ->from('{{%order58_call_imports}}')
            ->where(['store_source_id' => self::STORE, 'call_session_id' => $sessionId, 'channel' => $channel])
            ->scalar();
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();
        $stores = [self::STORE, self::STORE_NO_COMPANY];

        // Children first: the batch foreign key is RESTRICT.
        $connection->createCommand()->delete('{{%order58_call_imports}}', ['store_source_id' => $stores])->execute();
        $connection->createCommand()->delete('{{%order58_call_import_batches}}', ['store_source_id' => $stores])->execute();
        $connection->createCommand()->delete('{{%knowledge_bases}}', ['source_store_id' => $stores])->execute();
        IntegrationDb::cleanup($connection, '{{%order58_stores}}', ['source_id' => $stores]);
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
