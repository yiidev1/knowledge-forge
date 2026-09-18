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

use function array_map;
use function bin2hex;
use function gmdate;
use function implode;
use function json_encode;
use function random_bytes;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function pack;
use function str_repeat;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * The AI training audio screens, against the real served application.
 *
 * **No test here generates anything.** Nothing reaches Deepgram, no worker runs during this suite, and
 * that is the assertion rather than a limitation: the page reads, the button enqueues, and the money is
 * spent in `kf:audio:tts-worker` — a separate command on a separate schedule.
 *
 * The facts pinned here are the ones a template could quietly get wrong and nobody would notice until
 * an invoice arrived: the opt-in is off unless somebody ticks it, and every way of asking for a file
 * that does not exist gives the same answer.
 */
final class AudioToTextAiAudioCest
{
    private const ADMIN = '__kf_a2t_tts_admin__';
    private const PASSWORD = 'AiAudioPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    private const STORE = 987654331;
    private const STORE_NAME = '__KF AI Audio Store__';

    private ConnectionInterface $connection;
    private string $conversationPublicId = '';
    private string $jobPublicId = '';

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->createStore();
        $this->createCompletedMixedConversation();
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ authorisation

    public function aGuestCannotReachTheAiAudioPage(WebTester $I): void
    {
        $I->amOnPage($this->pageUrl());
        $I->seeCurrentUrlEquals('/login');
    }

    public function aGuestCannotReachTheFile(WebTester $I): void
    {
        $I->amOnPage($this->fileUrl($this->jobPublicId));
        $I->seeCurrentUrlEquals('/login');
    }

    // ------------------------------------------------------------------ the upload opt-in

    /**
     * **Off by default, every time.**
     *
     * The single most expensive thing this feature could get wrong is a checkbox that remembers. Every
     * upload would then quietly buy audio nobody asked for.
     */
    public function theAiAudioOptInIsUncheckedOnBothUploadForms(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/audio-to-text/store/' . self::STORE);

        $I->seeResponseCodeIs(200);
        $I->see('Generate clean AI audio after transcription');
        $I->see('Costs money. Off by default.');

        $I->dontSeeCheckboxIsChecked('#a2t-common-ai-audio');
        $I->dontSeeCheckboxIsChecked('#a2t-separate-ai-audio');
    }

    public function theConversionsTableLinksToTheAiAudioPage(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/audio-to-text/store/' . self::STORE);

        $I->seeElement('a[href="' . $this->pageUrl() . '"]');
        $I->see('AI audio');
    }

    // ------------------------------------------------------------------ the page

    public function thePageNamesTheTranscriptItWouldRead(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->pageUrl());

        $I->seeResponseCodeIs(200);
        $I->see('AI training audio');
        $I->see('Audio Conversion Details');
        // The promise the whole feature rests on, stated on the page rather than only in a docblock.
        $I->see('Nothing is summarised, reworded or re-priced.');
    }

    /**
     * The three stages are shown as one strip, in order, each carrying its own state.
     *
     * This is the redesign's whole purpose: a reader who has never seen the feature can follow what
     * produced what without being told.
     */
    public function thePageShowsTheOriginalToTranscriptToAiAudioFlow(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->pageUrl());

        $I->seeElement('.a2t-flow');
        $I->seeNumberOfElements('.a2t-flow__step', 3);
        $I->see('Step 1');
        $I->see('Step 2');
        $I->see('Step 3');
    }

    /**
     * The uploaded recording is offered as a player, pointed at the route that streams it.
     *
     * The page is about "this recording became that reading", which cannot be checked by ear unless both
     * are playable. The `src` is asserted rather than merely the element, because a player pointed at
     * nothing looks identical to a working one until somebody presses play.
     */
    public function theOriginalRecordingIsOfferedAsAPlayer(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->pageUrl());

        $I->see('call.wav');
        $I->seeElement('audio[src="' . $this->originalFileUrl() . '"]');
        $I->seeElement('a[href="' . $this->originalFileUrl() . '?download=1"]');
    }

    /** A recording the server no longer holds gets no player, rather than one that cannot work. */
    public function aRecordingThatWasNotRetainedOffersNoOriginalPlayer(WebTester $I): void
    {
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['retained_audio_path' => null],
            ['public_id' => $this->jobPublicId],
        )->execute();

        $this->signIn($I);
        $I->amOnPage($this->pageUrl());

        $I->dontSeeElement('audio[src="' . $this->originalFileUrl() . '"]');
        $I->see('no longer stored on the server');
    }

    /** A mixed recording offers one output. The per-role extracts are not part of this phase. */
    public function aMixedRecordingOffersOnlyMixedAudio(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->pageUrl());

        $I->see('Mixed AI audio');
        $I->dontSee('Customer AI audio');
        $I->dontSee('Agent AI audio');
    }

    public function nothingHasBeenGeneratedYet(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->pageUrl());

        $I->see('Not generated');
        // No *generated* player, because there is nothing to play. The original's player is a different
        // element and is expected here — asserting on the src is what keeps the two apart.
        $I->dontSeeElement('audio[src*="ai-audio/file"]');
    }

    /** The cost is shown before the click, not discovered after it. */
    public function theCharacterCountIsStatedBeforeTheButton(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->pageUrl());

        $I->see('will be sent to the speech provider');
        $I->see('This is a paid action.');
    }

    /**
     * No inline script or style anywhere: the policy is `script-src 'self'; style-src 'self'`, and a
     * native `<audio controls>` with ordinary forms needs neither.
     */
    public function thePageNeedsNoInlineJavaScript(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->pageUrl());

        $I->dontSeeElement('script', ['type' => 'text/javascript']);
        $I->dontSee('onclick=');
    }

    // ------------------------------------------------------------------ the file route

    /**
     * Every way of asking for something that is not there gives the same answer.
     *
     * 404 rather than 403 throughout: a 403 would confirm that an id exists, which is exactly what a
     * 32-character random public id is there to hide.
     */
    public function anUnknownRecordingIs404(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->fileUrl(bin2hex(random_bytes(16))));
        $I->seeResponseCodeIs(404);
    }

    public function aRecordingWithNothingGeneratedIs404(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->fileUrl($this->jobPublicId));
        $I->seeResponseCodeIs(404);
    }

    // ------------------------------------------------------------------ the original recording's bytes

    /** The bytes served are the bytes on disk — the assertion a status code alone would not make. */
    public function theOriginalRecordingIsServedInFull(WebTester $I): void
    {
        [$directory, $bytes] = $this->writeRetainedRecording();

        try {
            $this->signIn($I);
            $I->amOnPage($this->originalFileUrl());

            $I->seeResponseCodeIs(200);
            Assert::assertSame($bytes, $I->grabPageSource());
        } finally {
            $this->removeRetainedRecording($directory);
        }
    }

    /**
     * A range is answered with a 206 and exactly those bytes.
     *
     * Safari opens an `<audio>` element with `Range: bytes=0-1` and will not play a source that answers
     * 200, so this is what makes the player work at all rather than a refinement of it.
     */
    public function aByteRangeOfTheOriginalIsAnsweredWithJustThoseBytes(WebTester $I): void
    {
        [$directory, $bytes] = $this->writeRetainedRecording();

        try {
            $this->signIn($I);
            $I->haveHttpHeader('Range', 'bytes=0-3');
            $I->amOnPage($this->originalFileUrl());

            $I->seeResponseCodeIs(206);
            // 'RIFF', and proof the emitter's rewind did not restart the stream at byte zero and run on.
            Assert::assertSame(substr($bytes, 0, 4), $I->grabPageSource());
        } finally {
            $I->deleteHeader('Range');
            $this->removeRetainedRecording($directory);
        }
    }

    /** A range past the end is corrected with the true length, not guessed at. */
    public function anUnsatisfiableRangeOfTheOriginalIs416(WebTester $I): void
    {
        [$directory, $bytes] = $this->writeRetainedRecording();

        try {
            $this->signIn($I);
            $I->haveHttpHeader('Range', 'bytes=' . (strlen($bytes) + 10) . '-');
            $I->amOnPage($this->originalFileUrl());

            $I->seeResponseCodeIs(416);
        } finally {
            $I->deleteHeader('Range');
            $this->removeRetainedRecording($directory);
        }
    }

    /** No row, no file, and a job that retained nothing all answer the same way. */
    public function everyWayOfAskingForAnAbsentOriginalIs404(WebTester $I): void
    {
        $this->signIn($I);

        // Unknown job.
        $I->amOnPage($this->originalFileUrl(bin2hex(random_bytes(16))));
        $I->seeResponseCodeIs(404);

        // Known job, column set, but nothing on disk.
        $I->amOnPage($this->originalFileUrl());
        $I->seeResponseCodeIs(404);

        // Known job that retained nothing at all.
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['retained_audio_path' => null],
            ['public_id' => $this->jobPublicId],
        )->execute();

        $I->amOnPage($this->originalFileUrl());
        $I->seeResponseCodeIs(404);
    }

    public function aGuestCannotReachTheOriginalRecording(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage($this->originalFileUrl());
        $I->seeCurrentUrlEquals('/login');
    }

    public function anOutputTypeThisRecordingCannotProduceIs404(WebTester $I): void
    {
        $this->signIn($I);

        // A mixed recording has no separate Customer side in this phase.
        $I->amOnPage($this->fileUrl($this->jobPublicId) . '?type=customer');
        $I->seeResponseCodeIs(404);
    }

    /** A malformed id never reaches the action: the route constraint rejects it first. */
    public function aMalformedIdIsRefusedByTheRoute(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/not-a-public-id/ai-audio/file');
        $I->seeResponseCodeIsClientError();
    }

    // ------------------------------------------------------------------ generating

    /** Spending money is a POST. A GET that spends is a GET somebody can be tricked into making. */
    public function generatingIsNotReachableByGet(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $this->jobPublicId . '/ai-audio/generate');
        $I->seeResponseCodeIsClientError();
    }

    /**
     * A forged token buys nothing.
     *
     * The form is submitted exactly as a browser would, with only the CSRF token replaced — so what is
     * under test is the server's rejection rather than a hand-made request that skipped the form.
     */
    public function generatingWithAForgedCsrfTokenIsRefused(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->pageUrl());

        $I->submitForm('form[action*="ai-audio/generate"]', [
            '_csrf' => str_repeat('0', 64),
            'output_type' => 'MIXED',
        ]);

        $I->seeResponseCodeIsClientError();
        $this->assertNoRenditionExists($I);
    }

    /**
     * And the genuine form does queue — one row, and nothing generated in the request itself.
     *
     * The second half is the important one: no provider is contacted here, so an upload page can never
     * be made to wait on a third party. `WebTierCannotRunWhisperTest` makes that structural.
     */
    public function pressingGenerateQueuesExactlyOneRendition(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->pageUrl());

        $I->submitForm('form[action*="ai-audio/generate"]', []);

        $I->seeResponseCodeIs(200);
        $I->see('has been queued');
        Assert::assertSame(1, $this->renditionCount(), 'One press, one rendition.');
        Assert::assertSame('QUEUED', $this->renditionStatus(), 'Nothing is generated inside the request.');
    }

    /**
     * Once something is queued the button goes away — the first line of defence against a second charge.
     *
     * Not the only one: two requests arriving together both get past any page-level check, and the
     * conditional update in the repository is what decides between them. That race is covered against
     * real MySQL in `TtsRenditionRepositoryTest`, because it is a database guarantee and cannot honestly
     * be tested through a browser.
     */
    public function onceQueuedThePageStopsOfferingTheButton(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage($this->pageUrl());
        $I->submitForm('form[action*="ai-audio/generate"]', []);

        $I->amOnPage($this->pageUrl());

        $I->see('Queued');
        $I->dontSeeElement('form[action*="ai-audio/generate"]');
        $I->see('A generation is already under way.');
        Assert::assertSame(1, $this->renditionCount());
    }

    // ------------------------------------------------------------------ fixtures

    private function assertNoRenditionExists(WebTester $I): void
    {
        Assert::assertSame(0, $this->renditionCount(), 'A refused request must not leave a queued generation behind.');
    }

    private function renditionCount(): int
    {
        return (int) $this->connection->createCommand(
            'SELECT COUNT(*) FROM {{%audio_tts_renditions}} r
             JOIN {{%audio_transcription_jobs}} j ON j.id = r.job_id
             WHERE j.public_id = :p',
            [':p' => $this->jobPublicId],
        )->queryScalar();
    }

    private function renditionStatus(): string
    {
        return (string) $this->connection->createCommand(
            'SELECT r.status FROM {{%audio_tts_renditions}} r
             JOIN {{%audio_transcription_jobs}} j ON j.id = r.job_id
             WHERE j.public_id = :p',
            [':p' => $this->jobPublicId],
        )->queryScalar();
    }

    /**
     * A completed mixed recording with published roles — the state an administrator would be looking at.
     *
     * Written directly rather than by running the pipeline: this suite starts no worker, and the point of
     * these tests is the screens, not the transcription.
     */
    private function createCompletedMixedConversation(): void
    {
        $this->conversationPublicId = bin2hex(random_bytes(16));
        $this->jobPublicId = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');

        $adminId = (int) $this->connection
            ->createCommand('SELECT id FROM {{%admin_users}} WHERE username = :u', [':u' => self::ADMIN])
            ->queryScalar();

        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => $this->conversationPublicId,
            'store_source_id' => self::STORE,
            'mode' => 'COMMON',
            'generate_ai_audio' => 0,
            'uploaded_by_admin_id' => $adminId,
            'created_at' => $now,
        ])->execute();

        $conversationId = (int) $this->connection->getLastInsertID();

        $segments = json_encode([
            ['start_ms' => 0, 'end_ms' => 2000, 'speaker' => 'SPEAKER_00', 'role' => 'CUSTOMER',
                'text' => 'Two egg foo young, no MSG.', 'confidence' => 0.9],
            ['start_ms' => 2200, 'end_ms' => 4000, 'speaker' => 'SPEAKER_01', 'role' => 'AGENT',
                'text' => 'Ready in 25 minutes.', 'confidence' => 0.9],
        ], JSON_THROW_ON_ERROR);

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => $this->jobPublicId,
            'conversation_id' => $conversationId,
            'source_role' => 'COMMON',
            'uploaded_by_admin_id' => $adminId,
            'status' => 'COMPLETED',
            'processing_stage' => 'COMPLETED',
            'original_filename' => 'call.wav',
            'retained_audio_path' => 'source.wav',
            'duration_seconds' => 12.5,
            'transcript' => 'Two egg foo young, no MSG. Ready in 25 minutes.',
            'agent_text' => 'Ready in 25 minutes.',
            'customer_text' => 'Two egg foo young, no MSG.',
            'speaker_segments' => $segments,
            // COMPLETED is what makes the roles publishable without a human confirmation, so the page
            // offers generation rather than asking for the speakers to be confirmed first.
            'speaker_separation_status' => 'COMPLETED',
            'speaker_separation_method' => 'sherpa-onnx',
            'speaker_role_confidence' => 0.9,
            'created_at' => $now,
            'started_at' => $now,
            'completed_at' => $now,
        ])->execute();
    }

    /** Both rows: the store lookup joins the mirror to its knowledge base for the name on the page. */
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
            'slug' => 'kf-ai-audio-store-' . self::STORE,
            'source_system' => 'order58',
            'source_store_id' => self::STORE,
            'source_name' => self::STORE_NAME,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /**
     * Scoped to this suite's own rows, never a blanket delete.
     *
     * This database is shared with real use and conversations are kept indefinitely, so an unscoped
     * delete here would destroy somebody's actual recordings. Scoped by **this suite's administrator**
     * rather than by store, following `AudioToTextStoreCest`: the uploader is the one column no test has
     * a reason to change, and both foreign keys are RESTRICT so children go before parents.
     */
    private function cleanup(): void
    {
        $adminIds = (new Query($this->connection))
            ->select('id')
            ->from('{{%admin_users}}')
            ->where(['username' => self::ADMIN])
            ->column();

        if ($adminIds !== []) {
            // Renditions cascade from the job, but they are removed first so a part-finished setup still
            // leaves nothing behind.
            $this->connection->createCommand(
                'DELETE r FROM {{%audio_tts_renditions}} r
                 JOIN {{%audio_transcription_jobs}} j ON j.id = r.job_id
                 WHERE j.uploaded_by_admin_id IN (' . implode(',', array_map('intval', $adminIds)) . ')',
            )->execute();

            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['uploaded_by_admin_id' => $adminIds])
                ->execute();
            $this->connection->createCommand()
                ->delete('{{%audio_conversations}}', ['uploaded_by_admin_id' => $adminIds])
                ->execute();
        }

        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['source_store_id' => self::STORE]);
        IntegrationDb::cleanup($this->connection, '{{%order58_stores}}', ['source_id' => self::STORE]);
        IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => self::ADMIN]);
    }

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    private function pageUrl(): string
    {
        return '/audio-to-text/conversion/' . $this->conversationPublicId . '/ai-audio';
    }

    private function fileUrl(string $jobPublicId): string
    {
        return '/audio-to-text/job/' . $jobPublicId . '/ai-audio/file';
    }

    private function originalFileUrl(?string $jobPublicId = null): string
    {
        return '/audio-to-text/job/' . ($jobPublicId ?? $this->jobPublicId) . '/original/file';
    }

    /**
     * Put real bytes where the endpoint will look for them, and answer with what they are.
     *
     * A RIFF/WAVE header plus a little silence: enough that the response can be checked byte for byte,
     * small enough to live in a test. Written under the served application's own runtime tree, because
     * the point is to exercise the real resolver rather than a stub of it.
     *
     * @return array{0: string, 1: string} the directory to remove afterwards, and the bytes written
     */
    private function writeRetainedRecording(): array
    {
        $directory = dirname(__DIR__, 2) . '/runtime/audio-to-text/recordings/' . $this->jobPublicId;

        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $samples = str_repeat("\0", 512);
        $bytes = 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            . 'data' . pack('V', strlen($samples)) . $samples;

        file_put_contents($directory . '/source.wav', $bytes);

        return [$directory, $bytes];
    }

    private function removeRetainedRecording(string $directory): void
    {
        @unlink($directory . '/source.wav');
        @rmdir($directory);
    }
}
