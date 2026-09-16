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
use function is_file;
use function pack;
use function str_repeat;
use function strlen;
use function unlink;

/**
 * Choosing a transcription provider, end to end against the real served application.
 *
 * **No test here starts ffmpeg, whisper, or a Deepgram request.** Uploads reach QUEUED and stop,
 * because no worker runs during this suite.
 *
 * Six tests exercise the "this provider cannot run here" path and therefore need an unconfigured
 * provider to look at. They read that precondition off the served page and skip when every provider is
 * configured, rather than assuming a particular `.env`. An earlier version of this file did assume it,
 * and turned green-to-red the day a Deepgram key was added — a property of the machine, not of the
 * code. The unit suite covers the same behaviour unconditionally, building its own settings.
 *
 * PhpBrowser runs no JavaScript, which makes this file the no-JavaScript proof as well: the settings
 * dialog is opened here by following the trigger's real href, exactly as a script-less browser would.
 *
 * The facts pinned here are the ones a template or an action could quietly get wrong:
 *
 * 1. **The default is a default.** It decides what the form preselects. It is not re-read when the job
 *    runs, and an upload never writes to it.
 * 2. **The provider field is always rendered**, in both upload forms. An unusable provider is shown
 *    disabled and explained, never omitted — and the check behind that is local configuration, so the
 *    page makes no network call to any provider to decide it.
 * 3. **The global setting and a per-upload choice are different things.** Neither may silently become
 *    the other, and neither is rewritten because the other is inconvenient.
 */
final class AudioToTextProviderCest
{
    private const ADMIN = '__kf_a2t_provider_admin__';
    private const PASSWORD = 'AudioProviderPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    private const STORE = 987654331;
    private const STORE_NAME = '__KF Audio Provider Store__';

    private const PICKER_URL = '/admin/order58/store-audio';
    private const SETTINGS_URL = '/audio-to-text/settings/default-provider';

    private ConnectionInterface $connection;

    /** The shared settings row survives this suite unchanged, whatever the tests do to it. */
    private string $originalDefault = 'WHISPER';

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->originalDefault = $this->storedDefault();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->createStore();
        $this->writeFixtures();
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
        $this->removeFixtures();
    }

    // ------------------------------------------------------------------------ the settings form

    public function aGuestCannotReachTheSettingsPage(WebTester $I): void
    {
        $I->amOnPage(self::PICKER_URL);
        $I->seeCurrentUrlEquals('/login');
    }

    /**
     * The setting is reached by a button, not by a card sitting on the page.
     *
     * The store picker is what an administrator came here for; a permanent settings card for something
     * changed once a quarter pushed it below the fold.
     */
    public function thePickerOffersTranscriptionSettingsBesideAllConversions(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL);
        $I->seeResponseCodeIs(200);

        $I->seeElement('.page-header__actions [data-a2t-settings-open]');
        $I->see('Transcription settings', '.page-header__actions');
        $I->see('All conversions', '.page-header__actions');
    }

    /**
     * The inline card is gone — the form now lives inside the dialog and nowhere else.
     *
     * Asserted by shape rather than by text: the form is still on the page (inside `<dialog>`), so the
     * thing to prove is that it is no longer a `.card` in the document flow.
     */
    public function thePickerNoLongerShowsAnInlineSettingsCard(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL);

        $I->dontSeeElement('.card form[action="' . self::SETTINGS_URL . '"]');
        $I->seeElement('dialog[data-a2t-settings-dialog] form[action="' . self::SETTINGS_URL . '"]');
    }

    /**
     * The trigger is a real link, which is what keeps the one global setting reachable without
     * JavaScript — `admin.js` intercepts the same click and calls showModal() instead.
     *
     * This test IS the no-JavaScript path: PhpBrowser runs no scripts, so following the href is
     * exactly what a script-less browser does.
     */
    public function theSettingsLinkOpensTheDialogWithoutJavaScript(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL);
        $I->click('[data-a2t-settings-open]');

        $I->seeElement('dialog[data-a2t-settings-dialog][open]');
        $I->see('Transcription settings');
        $I->see('Default transcription provider');
        $I->see('Choose which provider should be selected automatically');
    }

    /** Closed unless it was asked for, so the page it sits on is unchanged for everyone else. */
    public function theDialogIsClosedUntilItIsAskedFor(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL);
        $I->dontSeeElement('dialog[data-a2t-settings-dialog][open]');
    }

    /** Seeded by the migration, so the dialog has something coherent to show from the first request. */
    public function theDialogShowsTheCurrentGlobalProvider(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL . '?settings=1');
        $I->seeElement('#a2t-default-provider option[value="WHISPER"][selected]');
    }

    /** Every POST here carries the application-wide token, exactly as the inline form did. */
    public function theDialogFormCarriesACsrfToken(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL . '?settings=1');
        $I->seeElement('dialog[data-a2t-settings-dialog] input[name="_csrf"]');
    }

    /** A POST without the token is refused by the application-wide middleware. */
    public function theSettingsPostIsRefusedWithoutACsrfToken(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL . '?settings=1');
        $I->submitForm(
            'dialog[data-a2t-settings-dialog] form',
            ['_csrf' => 'not-the-right-token', 'transcription_provider' => 'WHISPER'],
        );

        $I->seeResponseCodeIs(422);
        Assert::assertSame('WHISPER', $this->storedDefault());
    }

    /**
     * Every provider is listed here, configured or not.
     *
     * The settings page is where an administrator decides what the installation should use; hiding an
     * option would leave no way to discover that it exists. The *upload* form is the one that offers
     * only what can run — see below — and the POST refuses an unconfigured choice with a reason.
     */
    public function bothProvidersAreListedInTheSetting(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL . '?settings=1');
        $I->seeElement('#a2t-default-provider option[value="WHISPER"]');
        $I->seeElement('#a2t-default-provider option[value="DEEPGRAM"]');
    }

    /**
     * Deepgram has no key in the test environment, so it cannot be made the default.
     *
     * The refusal is decided from local configuration alone — no request leaves this server to find
     * out — and it says which provider and why.
     */
    public function anUnconfiguredProviderCannotBecomeTheDefault(WebTester $I): void
    {
        $this->signIn($I);
        $this->skipUnlessAProviderIsUnavailable($I);

        $I->amOnPage(self::PICKER_URL . '?settings=1');
        $I->submitForm('form[action="' . self::SETTINGS_URL . '"]', ['transcription_provider' => 'DEEPGRAM']);

        $I->see('is not configured on this server yet');
        Assert::assertSame('WHISPER', $this->storedDefault(), 'The setting must be unchanged.');
    }

    /** `fromStorage()` returns null rather than defaulting, precisely so this is a refusal. */
    public function anUnknownProviderIsRejected(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL . '?settings=1');
        $I->submitForm('form[action="' . self::SETTINGS_URL . '"]', ['transcription_provider' => 'GOOGLE']);

        $I->see('not a transcription provider this server knows about');
        Assert::assertSame('WHISPER', $this->storedDefault());
    }

    /** Saving the provider that is already selected is accepted and idempotent. */
    public function savingTheCurrentDefaultSucceeds(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PICKER_URL . '?settings=1');
        $I->submitForm('form[action="' . self::SETTINGS_URL . '"]', ['transcription_provider' => 'WHISPER']);

        $I->see('by default');
        Assert::assertSame('WHISPER', $this->storedDefault());
        Assert::assertSame(1, $this->settingsRowCount(), 'The settings table holds exactly one row.');
    }

    // ------------------------------------------------------------------------ the upload forms

    /**
     * **The field is always there, in both forms.**
     *
     * It used to disappear when only one provider was usable — which is the normal state of this test
     * environment. That hid which engine an upload would use, and made a broken install look identical
     * to a single-provider one.
     */
    public function bothUploadFormsAlwaysRenderTheProviderField(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage($this->storeUrl());
        $I->seeResponseCodeIs(200);

        $I->seeElement('#a2t-common-form select[name="transcription_provider"]#a2t-common-provider');
        $I->seeElement('#a2t-separate-form select[name="transcription_provider"]#a2t-separate-provider');
        $I->see('Transcription provider');
    }

    /**
     * An unusable provider is shown and disabled, not omitted.
     *
     * Deepgram has no key in this environment, so this is the real state of the page rather than a
     * contrived one. The explanation is user-safe: which setting is missing is never printed.
     */
    public function anUnconfiguredProviderIsVisibleButDisabled(WebTester $I): void
    {
        $this->signIn($I);
        $this->skipUnlessAProviderIsUnavailable($I);

        $I->amOnPage($this->storeUrl());

        $I->seeElement('#a2t-common-provider option[value="DEEPGRAM"][disabled]');
        $I->seeElement('#a2t-separate-provider option[value="DEEPGRAM"][disabled]');
        $I->seeElement('#a2t-common-provider option[value="WHISPER"]:not([disabled])');

        $I->see('Not configured');
        $I->see('Deepgram is not currently configured on this server.');

        // User-safe: the reason names the provider, never the setting behind it.
        $I->dontSee('DEEPGRAM_API_KEY');
    }

    /** Which one this upload will use, and that it is only this upload. */
    public function theFieldNamesTheGlobalDefaultAndItsOwnScope(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage($this->storeUrl());

        $I->see('Global default: Whisper (local).');
        $I->see('This choice applies only to this upload.');
    }

    /** The global default is what both forms start on — the `providerIsUsable(default)` branch. */
    public function bothFormsPreselectTheGlobalDefault(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage($this->storeUrl());

        $I->seeElement('#a2t-common-provider option[value="WHISPER"][selected]');
        $I->seeElement('#a2t-separate-provider option[value="WHISPER"][selected]');
    }

    // ------------------------------------------- the default names a provider that cannot run

    /**
     * **The edge case.** The stored default is Deepgram; Deepgram is not configured here.
     *
     * The field must not vanish, the stored setting must not be quietly rewritten, and the form must
     * not be left in a state that cannot be submitted. So an available provider is preselected and the
     * page says plainly that the configured default is unavailable.
     */
    public function anUnavailableGlobalDefaultIsReportedRatherThanRewritten(WebTester $I): void
    {
        $this->signIn($I);
        $this->skipUnlessAProviderIsUnavailable($I);
        $this->forceStoredDefault('DEEPGRAM');

        $I->amOnPage($this->storeUrl());
        $I->seeResponseCodeIs(200);

        // The field is still there, in both forms.
        $I->seeElement('#a2t-common-provider');
        $I->seeElement('#a2t-separate-provider');

        // An available provider is preselected instead — the form stays submittable.
        $I->seeElement('#a2t-common-provider option[value="WHISPER"][selected]');
        $I->seeElement('#a2t-separate-provider option[value="WHISPER"][selected]');
        $I->dontSeeElement('#a2t-common-provider option[value="DEEPGRAM"][selected]');

        // And it is said out loud, naming the configured default.
        $I->see('The global default is Deepgram (cloud), which is not available on this server');
        $I->see('The global setting has not been changed.');

        // The stored setting is untouched by merely looking at the page.
        Assert::assertSame('DEEPGRAM', $this->storedDefault());
    }

    /** Rendering the page must never repair the setting on the administrator's behalf. */
    public function anUnavailableGlobalDefaultSurvivesAnUpload(WebTester $I): void
    {
        $this->signIn($I);
        $this->skipUnlessAProviderIsUnavailable($I);
        $this->forceStoredDefault('DEEPGRAM');

        $I->amOnPage($this->storeUrl());
        $I->attachFile('#a2t-audio', 'kf_provider_valid.wav');
        $I->submitForm('#a2t-common-form', []);

        // The upload went through on the preselected, available provider …
        Assert::assertSame(['WHISPER'], $this->queuedProviders());
        // … and the broken global setting is still exactly as the administrator left it.
        Assert::assertSame('DEEPGRAM', $this->storedDefault());
    }

    /** Choosing the unavailable provider explicitly is still refused, before anything is stored. */
    public function anUnavailableGlobalDefaultCannotBeForcedThroughTheForm(WebTester $I): void
    {
        $this->signIn($I);
        $this->skipUnlessAProviderIsUnavailable($I);
        $this->forceStoredDefault('DEEPGRAM');

        $I->amOnPage($this->storeUrl());
        $I->attachFile('#a2t-audio', 'kf_provider_valid.wav');
        $I->submitForm('#a2t-common-form', ['transcription_provider' => 'DEEPGRAM']);

        $I->see('is not configured on this server yet');
        Assert::assertSame([], $this->queuedProviders());
    }

    /** And the upload still works when the field is simply left alone. */
    public function anUploadThatLeavesTheFieldAloneUsesTheDefault(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage($this->storeUrl());
        $I->attachFile('#a2t-audio', 'kf_provider_valid.wav');
        $I->submitForm('#a2t-common-form', []);

        Assert::assertSame(['WHISPER'], $this->queuedProviders());
    }

    /**
     * **An upload never writes the global setting.**
     *
     * The two are separate by design: one person converting a single recording with a different engine
     * must not change what every other administrator's form preselects.
     */
    public function anUploadDoesNotChangeTheGlobalDefault(WebTester $I): void
    {
        $this->signIn($I);

        $before = $this->storedDefault();
        $updatedBefore = $this->settingsUpdatedAt();

        $I->amOnPage($this->storeUrl());
        $I->attachFile('#a2t-audio', 'kf_provider_valid.wav');
        $I->submitForm('#a2t-common-form', []);

        Assert::assertSame($before, $this->storedDefault());
        Assert::assertSame($updatedBefore, $this->settingsUpdatedAt(), 'The settings row must not be touched.');
    }

    /**
     * A posted provider this server cannot run is refused before anything is stored.
     *
     * Refusing here rather than letting the worker discover it saves a recording from being uploaded,
     * converted and then failed for a condition that was knowable at the click. Again: no network call
     * is made to decide this.
     */
    public function anUploadForAnUnconfiguredProviderIsRefusedBeforeQueueing(WebTester $I): void
    {
        $this->signIn($I);
        $this->skipUnlessAProviderIsUnavailable($I);

        $I->amOnPage($this->storeUrl());
        $I->attachFile('#a2t-audio', 'kf_provider_valid.wav');
        $I->submitForm('#a2t-common-form', ['transcription_provider' => 'DEEPGRAM']);

        $I->see('is not configured on this server yet');
        Assert::assertSame([], $this->queuedProviders(), 'Nothing may be queued for a provider that cannot run.');
    }

    public function anUploadWithAnUnknownProviderIsRefused(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage($this->storeUrl());
        $I->attachFile('#a2t-audio', 'kf_provider_valid.wav');
        $I->submitForm('#a2t-common-form', ['transcription_provider' => 'GOOGLE']);

        $I->see('Choose one of the listed transcription providers');
        Assert::assertSame([], $this->queuedProviders());
    }

    /** Both children of a pair carry one provider — one call, one engine. */
    public function bothRecordingsOfAPairShareTheProvider(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage($this->storeUrl());
        $I->attachFile('#a2t-customer-audio', 'kf_provider_customer.wav');
        $I->attachFile('#a2t-agent-audio', 'kf_provider_agent.wav');
        $I->submitForm('#a2t-separate-form', []);

        Assert::assertSame(['WHISPER', 'WHISPER'], $this->queuedProviders());
    }

    /** The provider a recording was transcribed with is visible on its own page. */
    public function theProviderIsShownOnTheJobPage(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage($this->storeUrl());
        $I->attachFile('#a2t-audio', 'kf_provider_valid.wav');
        $I->submitForm('#a2t-common-form', []);

        $I->amOnPage('/audio-to-text/job/' . $this->firstJobPublicId());
        $I->see('Transcribed by');
        $I->see('Whisper');
    }

    /**
     * Skips a test that can only be observed while a provider is genuinely unconfigured.
     *
     * Six tests below exercise the "this provider cannot run here" path: the disabled option, the
     * refusal on save, the unavailable-default notice. None of them can be observed on a machine where
     * every provider is configured, because there is no unavailable provider to render.
     *
     * The precondition is read from the **served page** rather than from `.env`, so the test agrees
     * with whatever the application actually believes — and a deployment that later adds a Deepgram key
     * skips these rather than failing them. That is the honest outcome: the behaviour is untested here,
     * not broken. It stays covered unconditionally by the unit suite, which builds its own settings.
     */
    private function skipUnlessAProviderIsUnavailable(WebTester $I): void
    {
        $I->amOnPage($this->storeUrl());

        try {
            $I->seeElement('#a2t-common-provider option[disabled]');
        } catch (\Throwable) {
            Assert::markTestSkipped(
                'Every transcription provider is configured on this machine, so there is no '
                . 'unavailable provider for this test to observe.',
            );
        }
    }

    // ---------------------------------------------------------------------------------- helpers

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    private function storeUrl(): string
    {
        return '/audio-to-text/store/' . self::STORE;
    }

    private function storedDefault(): string
    {
        return (string) $this->connection
            ->createCommand('SELECT default_transcription_provider FROM {{%audio_to_text_settings}} WHERE id = 1')
            ->queryScalar();
    }

    /**
     * Put a value into the settings row directly, bypassing the action.
     *
     * The action refuses to make an unconfigured provider the default — correctly — so the only way to
     * reach the "stored default names something that cannot run" state is to write it. That state is
     * real: a key can be removed from `.env` long after somebody chose Deepgram.
     *
     * `_after` restores whatever the row held before this suite ran.
     */
    private function forceStoredDefault(string $provider): void
    {
        $this->connection->createCommand(
            'UPDATE {{%audio_to_text_settings}} SET `default_transcription_provider` = :p WHERE `id` = 1',
            [':p' => $provider],
        )->execute();
    }

    private function settingsUpdatedAt(): string
    {
        return (string) $this->connection
            ->createCommand('SELECT updated_at FROM {{%audio_to_text_settings}} WHERE id = 1')
            ->queryScalar();
    }

    private function settingsRowCount(): int
    {
        return (int) $this->connection
            ->createCommand('SELECT COUNT(*) FROM {{%audio_to_text_settings}}')
            ->queryScalar();
    }

    /**
     * The stored provider of every job this suite queued, oldest first.
     *
     * Raw SQL rather than the repository: a NULL the hydrator would read as Whisper has to stay
     * distinguishable from an explicitly stored 'WHISPER'.
     *
     * @return list<string|null>
     */
    private function queuedProviders(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->createCommand(
            'SELECT j.transcription_provider AS p
             FROM {{%audio_transcription_jobs}} j
             JOIN {{%admin_users}} u ON u.id = j.uploaded_by_admin_id
             WHERE u.username = :u
             ORDER BY j.id ASC',
            [':u' => self::ADMIN],
        )->queryAll();

        $providers = [];
        foreach ($rows as $row) {
            $providers[] = $row['p'] === null ? null : (string) $row['p'];
        }

        return $providers;
    }

    private function firstJobPublicId(): string
    {
        return (string) $this->connection->createCommand(
            'SELECT j.public_id
             FROM {{%audio_transcription_jobs}} j
             JOIN {{%admin_users}} u ON u.id = j.uploaded_by_admin_id
             WHERE u.username = :u
             ORDER BY j.id ASC
             LIMIT 1',
            [':u' => self::ADMIN],
        )->queryScalar();
    }

    private function createStore(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => self::STORE,
            'name' => self::STORE_NAME,
            'active' => 1,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => self::STORE_NAME,
            'slug' => 'kf-audio-provider-' . self::STORE,
            'source_system' => 'order58',
            'source_store_id' => self::STORE,
            'source_name' => self::STORE_NAME,
            // The directory reads source-active from the knowledge base, not the store row.
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /**
     * Scoped by this suite's own administrator, and the settings row is restored rather than deleted.
     *
     * Children before parents before administrators: both foreign keys are RESTRICT, and
     * `audio_to_text_settings.updated_by_admin_id` is a third — which is why the setting's author is
     * cleared before the administrator it points at is removed.
     */
    private function cleanup(): void
    {
        $this->connection->createCommand(
            'UPDATE {{%audio_to_text_settings}}
             SET `default_transcription_provider` = :p, `updated_by_admin_id` = NULL
             WHERE `id` = 1',
            [':p' => $this->originalDefault],
        )->execute();

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
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['source_store_id' => self::STORE]);
        IntegrationDb::cleanup($this->connection, '{{%order58_stores}}', ['source_id' => self::STORE]);
    }

    /** A second of real silence: ffprobe reads these, and a header with no data has no duration. */
    private function writeFixtures(): void
    {
        $samples = str_repeat(pack('v', 0), 8000);
        $data = 'data' . pack('V', strlen($samples)) . $samples;
        $fmt = 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
            . pack('V', 8000) . pack('V', 16000) . pack('v', 2) . pack('v', 16);
        $body = 'WAVE' . $fmt . $data;
        $wav = 'RIFF' . pack('V', strlen($body)) . $body;

        foreach ($this->fixtureNames() as $file) {
            file_put_contents(codecept_data_dir($file), $wav);
        }
    }

    private function removeFixtures(): void
    {
        foreach ($this->fixtureNames() as $file) {
            $path = codecept_data_dir() . $file;

            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function fixtureNames(): array
    {
        return ['kf_provider_valid.wav', 'kf_provider_customer.wav', 'kf_provider_agent.wav'];
    }
}
