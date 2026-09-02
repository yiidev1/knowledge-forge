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

use function array_unique;
use function basename;
use function codecept_data_dir;
use function dirname;
use function file_put_contents;
use function glob;
use function gmdate;
use function is_dir;
use function is_file;
use function json_encode;
use function preg_match;
use function unlink;
use function pack;
use function str_repeat;
use function trim;

use const GLOB_ONLYDIR;
use const SORT_DESC;

/**
 * End-to-end, against the real served application.
 *
 * **No test here starts ffmpeg or whisper.** An upload reaches QUEUED and stops there, because no
 * worker is running — which is precisely the assertion: the HTTP request validates and queues, and
 * nothing else.
 *
 * The authorization block is the interesting one. This is a shared administrator demo, so Admin B can
 * see Admin A's job. That is deliberate and is what these tests pin; the boundary being defended is
 * "authorized administrator" versus "everyone else", not "uploader" versus "other administrators".
 */
final class AudioToTextCest
{
    private const ADMIN_A = '__kf_a2t_admin_a__';
    private const ADMIN_B = '__kf_a2t_admin_b__';
    private const PASSWORD = 'AudioTestPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $repository = new DbAdminUserRepository($this->connection, new SystemClock());
        $hasher = new NativePasswordHasher();

        $repository->create(self::ADMIN_A, $hasher->hash(self::PASSWORD));
        $repository->create(self::ADMIN_B, $hasher->hash(self::PASSWORD));

        $this->writeFixtures();
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ---------------------------------------------------------------- authentication

    public function aGuestIsSentToLoginFromEveryRoute(WebTester $I): void
    {
        $publicId = str_repeat('a', 32);

        foreach ([
            '/audio-to-text',
            '/audio-to-text/jobs',
            '/audio-to-text/job/' . $publicId,
            '/audio-to-text/job/' . $publicId . '/status',
            '/audio-to-text/job/' . $publicId . '/download',
        ] as $path) {
            $I->amOnPage($path);
            $I->seeCurrentUrlEquals('/login');
        }
    }

    public function anAuthenticatedAdministratorSeesTheUploadPage(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);

        $I->amOnPage('/audio-to-text');
        $I->seeResponseCodeIs(200);
        $I->see('Audio to Text');
        $I->see('Convert to Text');
        // The queue summary and worker status are part of the page, not a separate screen.
        $I->see('Queued');
        $I->see('Audio worker:');
    }

    /**
     * The Order58 agent realm is a separate authenticated tier, and Audio-to-Text is not part of it.
     *
     * An agent session is not an administrator session: the admin middleware looks for its own identity
     * and finds none, so every route redirects to the admin login exactly as it does for a guest.
     */
    public function anAgentSessionCannotReachAudioToText(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);

        // Agents sign in at their own endpoint; without an administrator identity the admin gate applies.
        $I->amOnPage('/agent/login');
        $I->seeResponseCodeIs(200);

        foreach (['/audio-to-text', '/audio-to-text/jobs'] as $path) {
            $I->amOnPage($path);
            $I->seeCurrentUrlEquals('/login');
        }
    }

    /** Signing out revokes access immediately, on every route. */
    public function signingOutRevokesAccess(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $I->amOnPage('/audio-to-text');
        $I->seeResponseCodeIs(200);

        $I->resetCookie(self::SESSION_COOKIE);

        $I->amOnPage('/audio-to-text');
        $I->seeCurrentUrlEquals('/login');
    }

    /** The pages this feature sits beside must keep working, sidebar entry included. */
    public function existingAdminPagesAreUnaffected(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);

        foreach (['/', '/admin/order58/stores', '/admin/order58/rules/readiness', '/admin/reports/chat'] as $path) {
            $I->amOnPage($path);
            $I->seeResponseCodeIs(200);
        }
    }

    // ---------------------------------------------------------------- upload validation

    public function submittingWithNoFileIsRejectedAndCreatesNoJob(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);

        $I->amOnPage('/audio-to-text');
        $I->submitForm('form[enctype="multipart/form-data"]', []);

        $I->see('Choose an audio file first.');
        Assert::assertSame(0, $this->jobCount());
    }

    public function anUnsupportedExtensionIsRejectedAndCreatesNoJob(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);

        $I->amOnPage('/audio-to-text');
        $I->attachFile('input[type=file]', 'kf_audio_fake.txt');
        $I->submitForm('form[enctype="multipart/form-data"]', []);

        $I->see('Only .wav, .mp3, .m4a, .ogg, .webm files are supported.');
        Assert::assertSame(0, $this->jobCount());
    }

    /** The extension says audio, the bytes say otherwise, and the bytes decide. */
    public function aNonAudioFileRenamedWavIsRejectedAndCreatesNoJob(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);

        $I->amOnPage('/audio-to-text');
        $I->attachFile('input[type=file]', 'kf_audio_disguised.wav');
        $I->submitForm('form[enctype="multipart/form-data"]', []);

        $I->see('That file is not audio, whatever its name says.');
        Assert::assertSame(0, $this->jobCount());
    }

    // ---------------------------------------------------------------- queueing

    public function aValidUploadCreatesExactlyOneQueuedJobAndRedirects(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);

        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $publicId);
        Assert::assertSame(1, $this->jobCount());

        $row = $this->jobRow($publicId);
        Assert::assertSame('QUEUED', $row['status']);
        Assert::assertSame('QUEUED', $row['processing_stage']);
        Assert::assertNull($row['transcript'], 'The web request must not transcribe anything.');
        Assert::assertNotNull($row['duration_seconds'], 'ffprobe runs during the upload request.');

        $I->see('Queued');
        $I->see('Stage:');
    }

    /**
     * The behaviour this replaced a restriction to get: an administrator may keep uploading.
     *
     * Previously a second upload while one was still active was refused with "You already have a
     * transcription in progress." That enforced one-at-a-time in the upload form, which stopped people
     * queueing work. The worker still processes one job at a time; the form no longer pretends to.
     */
    public function oneAdministratorMayUploadSeveralRecordingsInARow(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);

        $publicIds = [];
        for ($i = 0; $i < 3; ++$i) {
            $publicIds[] = $this->upload($I);
        }

        Assert::assertCount(3, array_unique($publicIds), 'Each upload must create its own job.');
        Assert::assertSame(3, $this->jobCount());

        foreach ($publicIds as $publicId) {
            Assert::assertSame('QUEUED', $this->jobRow($publicId)['status']);
        }

        $I->dontSee('You already have a transcription in progress');
    }

    /** Uploading is not blocked by an earlier job of one's own already being PROCESSING. */
    public function anotherRecordingMayBeUploadedWhileOneIsProcessing(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $first = $this->upload($I);

        // Simulate the worker having claimed it.
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['status' => 'PROCESSING', 'processing_stage' => 'TRANSCRIBING'],
            ['public_id' => $first],
        )->execute();

        $I->amOnPage('/audio-to-text');
        $I->see('Convert to Text');
        $I->dontSee('You already have a transcription in progress');

        $second = $this->upload($I);

        Assert::assertSame('QUEUED', $this->jobRow($second)['status']);
        Assert::assertSame('PROCESSING', $this->jobRow($first)['status'], 'The running job is untouched.');
        Assert::assertSame(2, $this->jobCount());
    }

    /** A waiting job tells the uploader where it is in line, without exposing any database id. */
    public function aQueuedJobShowsItsQueuePosition(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $this->upload($I);
        $second = $this->upload($I);

        $I->amOnPage('/audio-to-text/job/' . $second);
        $I->see('Queue position');
        $I->see('2');
    }

    /** Two administrators can both queue work at the same time. */
    public function severalAdministratorsMayQueueAtOnce(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $fromA = $this->upload($I);

        $this->signIn($I, self::ADMIN_B);
        $fromB = $this->upload($I);

        Assert::assertSame(2, $this->jobCount());

        // Each job's View link, which now opens the conversation page, is how a row is identified here.
        $I->amOnPage('/audio-to-text/jobs');
        $I->seeElement('a[href="/audio-to-text/job/' . $fromA . '/review"]');
        $I->seeElement('a[href="/audio-to-text/job/' . $fromB . '/review"]');
    }

    // ---------------------------------------------------------------- shared visibility

    /**
     * The behaviour this demo is built around: another authorized administrator sees the job, can poll
     * it, and can download from it.
     */
    public function anotherAdministratorCanViewPollAndDownloadTheSameJob(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);

        $this->completeJob($publicId, 'Hello? Yes, you want to place an order?');

        $this->signIn($I, self::ADMIN_B);

        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->seeResponseCodeIs(200);
        $I->see('Hello? Yes, you want to place an order?');

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/status');
        $I->seeResponseCodeIs(200);
        $I->see('COMPLETED');

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/download');
        $I->seeResponseCodeIs(200);
        $I->see('Hello? Yes, you want to place an order?');
    }

    /**
     * The list is shared, not per-uploader.
     *
     * Asserted on the job itself rather than on an uploader column: the table deliberately does not
     * carry one — it competed for width with the three transcript previews, and the uploader is shown
     * on the job page instead. Seeing Admin A's job while signed in as Admin B is the property that
     * matters, and it survives that presentational change.
     */
    public function theListIsGlobalAcrossAdministrators(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);

        $I->amOnPage('/audio-to-text/jobs');
        $I->seeResponseCodeIs(200);
        $I->see('Audio conversions');
        $I->seeElement('a[href="/audio-to-text/job/' . $publicId . '/review"]');

        $this->signIn($I, self::ADMIN_B);
        $I->amOnPage('/audio-to-text/jobs');
        $I->seeResponseCodeIs(200);
        $I->seeElement('a[href="/audio-to-text/job/' . $publicId . '/review"]');
    }

    /** The uploader is still recorded, and still shown — on the job page. */
    public function theJobPageNamesTheUploader(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);

        $this->signIn($I, self::ADMIN_B);
        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->see('Uploaded by');
        $I->see(self::ADMIN_A);
    }

    // ---------------------------------------------------------------- non-enumerability

    public function anUnknownJobIsNotFound(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);

        $I->amOnPage('/audio-to-text/job/' . str_repeat('b', 32));
        $I->seeResponseCodeIs(404);
    }

    /**
     * A malformed id never reaches an action: the route constraint rejects it first.
     */
    public function aMalformedJobIdIsNotFound(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);

        foreach (['not-a-valid-id', str_repeat('a', 31), str_repeat('z', 32)] as $bad) {
            $I->amOnPage('/audio-to-text/job/' . $bad);
            $I->seeResponseCodeIs(404);
        }
    }

    public function anIncompleteJobCannotBeDownloaded(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/download');
        $I->seeResponseCodeIs(404);
    }

    public function anUnavailableTranscriptPartIsNotFound(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);
        $this->completeJob($publicId, 'Complete transcript only.');

        // Speaker separation did not run, so there is no agent text to serve.
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/download?part=agent');
        $I->seeResponseCodeIs(404);
    }

    // ---------------------------------------------------------------- output safety

    public function theStatusEndpointReportsStatusStageAndSeparationOnly(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/status');
        $I->seeResponseCodeIs(200);
        $I->see('"status":"QUEUED"');
        $I->see('"stage":"QUEUED"');
        $I->dontSee('transcript');
    }

    public function aTranscriptContainingMarkupIsEscaped(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);
        $this->completeJob($publicId, '<script>alert(1)</script> & more');

        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->see('<script>alert(1)</script> & more');
        $I->dontSeeElement('.a2t-transcript script');
    }

    /**
     * Both pages render the same counters, because both read the same summary.
     *
     * The strip used to be computed from a cutoff each action worked out for itself. They agreed, but
     * only by both being wrong; sharing one source is what makes agreement structural.
     */
    public function bothPagesShowTheSameSummaryCounters(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);
        $this->completeJob($publicId, 'A finished conversation.');

        $I->amOnPage('/audio-to-text');
        $upload = $this->summaryCounters($I);

        $I->amOnPage('/audio-to-text/jobs');
        $list = $this->summaryCounters($I);

        Assert::assertSame(
            $upload,
            $list,
            'The upload page and the conversions list disagree about the queue summary.',
        );
    }

    /**
     * A job visible as Completed must appear in the completed counter.
     *
     * This is the reported bug in its simplest form: the list showed completed rows while the strip
     * above them read zero.
     */
    public function aCompletedJobIsCountedInTheCompletedTotal(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);

        $I->amOnPage('/audio-to-text/jobs');
        $before = $this->summaryCounters($I)['completed'];

        $publicId = $this->upload($I);
        $this->completeJob($publicId, 'A finished conversation.');

        $I->amOnPage('/audio-to-text/jobs');
        $after = $this->summaryCounters($I);

        Assert::assertSame($before + 1, $after['completed'], 'A completed job did not reach the counter.');
        Assert::assertSame(0, $after['queued'], 'A completed job must not still read as queued.');
        Assert::assertSame(0, $after['processing'], 'A completed job must not still read as processing.');
    }

    /** The invariant: an unpublished speaker split does not make the job any less completed. */
    public function aCompletedJobWithAnUnpublishedSplitStillCounts(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);

        $I->amOnPage('/audio-to-text/jobs');
        $before = $this->summaryCounters($I)['completed'];

        $publicId = $this->upload($I);
        $this->completeJobWithSeparation($publicId, 'NEEDS_REVIEW', 0.077, $this->mappedSegments());

        $I->amOnPage('/audio-to-text/jobs');

        $I->see('Needs review');
        Assert::assertSame($before + 1, $this->summaryCounters($I)['completed']);
    }

    /** The list pages rather than truncating with no way to reach the rest. */
    public function theConversionsListIsPaginated(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);

        $I->amOnPage('/audio-to-text/jobs');
        $I->seeResponseCodeIs(200);

        // Page 2 is always reachable by URL; with few rows it clamps back to the first page rather
        // than erroring, which is what a stale bookmark should do.
        $I->amOnPage('/audio-to-text/jobs?page=2');
        $I->seeResponseCodeIs(200);
        $I->see('Audio conversions');

        foreach (['?page=0', '?page=-3', '?page=abc', '?page=99999'] as $query) {
            $I->amOnPage('/audio-to-text/jobs' . $query);
            $I->seeResponseCodeIs(200);
            $I->see('Audio conversions');
        }
    }

    /** Paging must not change what the counters say — they describe the installation, not the page. */
    public function theSummaryIsTheSameOnEveryPageOfTheList(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);
        $this->completeJob($publicId, 'A finished conversation.');

        $I->amOnPage('/audio-to-text/jobs');
        $first = $this->summaryCounters($I);

        $I->amOnPage('/audio-to-text/jobs?page=2');
        $second = $this->summaryCounters($I);

        Assert::assertSame($first, $second);
    }

    /**
     * The limits an administrator is shown come from the configuration, so raising
     * `AUDIO_TRANSCRIPTION_MAX_DURATION` changes this page with nothing to edit in the template.
     */
    public function theUploadPageStatesTheCurrentDurationAndSizeLimits(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);

        $I->amOnPage('/audio-to-text');
        $I->see('5 minutes');
        $I->see('30 MB');
        // The old wording, and the old cap, must both be gone.
        $I->dontSee('120 seconds');
        $I->dontSee('15 MB');
    }

    /**
     * The inconsistency this block exists to prevent: the list showing an unpublished split while the
     * detail page labelled every turn Agent and Customer as though it were settled.
     */
    public function aNeedsReviewSplitNeverLabelsTurnsWithARole(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);
        // Roles are present in the stored segments — exactly as the mapper writes them for a result
        // that then fails the confidence gate. The status alone must suppress them.
        $this->completeJobWithSeparation($publicId, 'NEEDS_REVIEW', 0.077, $this->mappedSegments());

        $I->amOnPage('/audio-to-text/job/' . $publicId);

        $I->see('Speaker 1', '.a2t-turn__who');
        $I->see('Speaker 2', '.a2t-turn__who');
        $I->dontSee('Agent', '.a2t-turn__who');
        $I->dontSee('Customer', '.a2t-turn__who');

        // The detected conversation is still there to review, and the page says why it is unlabelled.
        $I->see('could not confidently determine which');
        $I->dontSeeElement('.a2t-split');
    }

    /** The guess may be shown, but only once, and only where it reads as a guess. */
    public function aNeedsReviewSplitPresentsItsRoleGuessAsTentative(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);
        $this->completeJobWithSeparation($publicId, 'NEEDS_REVIEW', 0.077, $this->mappedSegments());

        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->see('Likely Agent', '.a2t-hypothesis');
        $I->see('Likely Customer', '.a2t-hypothesis');
        $I->see('0.08', '.a2t-hypothesis');
    }

    public function aCompletedSplitLabelsTurnsWithRoles(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);
        $this->completeJobWithSeparation(
            $publicId,
            'COMPLETED',
            0.72,
            $this->mappedSegments(),
            'Hello, would you like to place an order?',
            'Yes, for delivery.',
        );

        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->see('Agent', '.a2t-turn__who');
        $I->see('Customer', '.a2t-turn__who');
        // A published role is a finding, so it is not restated as a guess.
        $I->dontSeeElement('.a2t-hypothesis');
    }

    /** The two pages must describe one state, which is the whole point of the exercise. */
    public function theListAndTheJobPageAgreeAboutAnUnpublishedSplit(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);
        $this->completeJobWithSeparation($publicId, 'NEEDS_REVIEW', 0.077, $this->mappedSegments());

        $I->amOnPage('/audio-to-text/jobs');
        $I->see('Needs review');

        $row = $this->jobRow($publicId);
        Assert::assertNull($row['agent_text']);
        Assert::assertNull($row['customer_text']);

        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->see('Needs review');
        $I->dontSee('Agent', '.a2t-turn__who');
    }

    /** No response may reveal where anything lives on this server. */
    public function noResponseLeaksAServerPath(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);
        $publicId = $this->upload($I);

        foreach (['/audio-to-text', '/audio-to-text/jobs', '/audio-to-text/job/' . $publicId] as $path) {
            $I->amOnPage($path);
            $I->dontSee('/var/www/');
            $I->dontSee('/opt/whisper');
            $I->dontSee('runtime/audio-to-text');
        }
    }

    public function theExistingAdminPagesStillWork(WebTester $I): void
    {
        $this->signIn($I, self::ADMIN_A);

        $I->amOnPage('/');
        $I->seeResponseCodeIs(200);
        $I->see('Welcome back');
        // The sidebar link is expected: the feature is deliberately discoverable.
        $I->see('Audio to Text');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Signs in from a clean session.
     *
     * The cookie reset is not incidental: submitting the login form while another administrator is
     * already authenticated signs that session out and lands back on `/login`. Starting fresh is also
     * the more honest simulation — "another administrator" means another browser, not a second identity
     * grafted onto the first one's session.
     */
    private function signIn(WebTester $I, string $username): void
    {
        // KFSESSID, not PHPSESSID: this application names its session cookie itself, and resetting
        // the wrong name is a silent no-op that leaves the previous administrator signed in.
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => $username, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    private function upload(WebTester $I): string
    {
        $I->amOnPage('/audio-to-text');
        $I->attachFile('input[type=file]', 'kf_audio_valid.wav');
        $I->submitForm('form[enctype="multipart/form-data"]', []);

        return $this->newestPublicId();
    }

    private function newestPublicId(): string
    {
        $adminIds = (new Query($this->connection))
            ->select('id')
            ->from('{{%admin_users}}')
            ->where(['username' => [self::ADMIN_A, self::ADMIN_B]])
            ->column();

        return (string) (new Query($this->connection))
            ->select('public_id')
            ->from('{{%audio_transcription_jobs}}')
            ->where(['uploaded_by_admin_id' => $adminIds])
            ->orderBy(['id' => SORT_DESC])
            ->limit(1)
            ->scalar();
    }

    /**
     * @return array<string, mixed>
     */
    private function jobRow(string $publicId): array
    {
        /** @var array<string, mixed> $row */
        $row = (array) (new Query($this->connection))
            ->from('{{%audio_transcription_jobs}}')
            ->where(['public_id' => $publicId])
            ->one();

        return $row;
    }

    /** Marks a job complete by hand — no worker runs during the web suite. */
    private function completeJob(string $publicId, string $transcript): void
    {
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            [
                'status' => 'COMPLETED',
                'processing_stage' => 'COMPLETED',
                'transcript' => $transcript,
                'detected_language' => 'en',
                'speaker_separation_status' => 'NOT_SUPPORTED',
                'stored_audio_path' => null,
                // Relative, not a fixed date. The completed counter covers a rolling 24 hours, so
                // a hard-coded timestamp silently falls out of the window once the calendar moves
                // past it and the counter assertions start failing for no reason. UTC, because
                // that is what the column stores.
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['public_id' => $publicId],
        )->execute();
    }

    /**
     * A finished job with a separation result, set up by hand — no worker runs during the web suite.
     *
     * `$agentText`/`$customerText` are passed only for a published split, mirroring the invariant the
     * service enforces: the aggregate columns stay NULL for every other status.
     */
    private function completeJobWithSeparation(
        string $publicId,
        string $separationStatus,
        float $confidence,
        string $segmentsJson,
        ?string $agentText = null,
        ?string $customerText = null,
    ): void {
        $this->completeJob($publicId, 'Hello, would you like to place an order? Yes, for delivery.');

        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            [
                'speaker_separation_status' => $separationStatus,
                'speaker_separation_method' => 'sherpa-onnx',
                'speaker_role_confidence' => $confidence,
                'speaker_segments' => $segmentsJson,
                'agent_text' => $agentText,
                'customer_text' => $customerText,
                'speaker_separation_completed_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['public_id' => $publicId],
        )->execute();
    }

    /**
     * Segments carrying AGENT/CUSTOMER roles — what the mapper stores whatever the eventual status,
     * and therefore the shape that made the old template treat an unpublished split as a settled one.
     */
    private function mappedSegments(): string
    {
        return (string) json_encode([
            [
                'start_ms' => 0,
                'end_ms' => 2000,
                'speaker' => 'SPEAKER_00',
                'role' => 'AGENT',
                'text' => 'Hello, would you like to place an order?',
                'confidence' => 0.9,
            ],
            [
                'start_ms' => 2000,
                'end_ms' => 4000,
                'speaker' => 'SPEAKER_01',
                'role' => 'CUSTOMER',
                'text' => 'Yes, for delivery.',
                'confidence' => 0.9,
            ],
        ]);
    }

    /**
     * The four rendered counters, read back off the page.
     *
     * Deliberately scraped from the DOM rather than queried from the database: the point of these
     * tests is what an administrator is actually shown, and a query would have re-implemented — and so
     * re-blessed — whatever the page happened to be doing.
     *
     * @return array{queued: int, processing: int, completed: int, failed: int}
     */
    private function summaryCounters(WebTester $I): array
    {
        $values = $I->grabMultiple('.a2t-count__value');

        Assert::assertCount(4, $values, 'Expected exactly four summary counters on the page.');

        return [
            'queued' => (int) trim($values[0]),
            'processing' => (int) trim($values[1]),
            'completed' => (int) trim($values[2]),
            'failed' => (int) trim($values[3]),
        ];
    }

    /** Counts only this suite's jobs, so an unrelated real conversation cannot skew an assertion. */
    private function jobCount(): int
    {
        $adminIds = (new Query($this->connection))
            ->select('id')
            ->from('{{%admin_users}}')
            ->where(['username' => [self::ADMIN_A, self::ADMIN_B]])
            ->column();

        if ($adminIds === []) {
            return 0;
        }

        return (int) (new Query($this->connection))
            ->from('{{%audio_transcription_jobs}}')
            ->where(['uploaded_by_admin_id' => $adminIds])
            ->count();
    }

    private function cleanup(): void
    {
        // Scoped to this suite's own administrators, never a blanket delete.
        //
        // Conversations are now retained indefinitely and this database is shared with real use, so a
        // `DELETE FROM audio_transcription_jobs` here would destroy someone's actual recordings and
        // transcripts. Deleting only what these tests created is the difference between a teardown and
        // an accident.
        //
        // Jobs before administrators: the foreign key is RESTRICT, so an administrator holding a job
        // cannot be removed. That is deliberate — see the migration — and it shapes the order here.
        $adminIds = (new Query($this->connection))
            ->select('id')
            ->from('{{%admin_users}}')
            ->where(['username' => [self::ADMIN_A, self::ADMIN_B]])
            ->column();

        if ($adminIds !== []) {
            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['uploaded_by_admin_id' => $adminIds])
                ->execute();
        }

        foreach ([self::ADMIN_A, self::ADMIN_B] as $username) {
            IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => $username]);
        }

        $this->removeJobDirectories();
        $this->removeFixtures();
    }

    /**
     * The fixtures are generated rather than checked in, so they are removed again — matching
     * `DocumentUploadCest`, and keeping `tests/Support/Data/` at just its `.gitkeep`.
     */
    private function removeFixtures(): void
    {
        foreach (['kf_audio_valid.wav', 'kf_audio_fake.txt', 'kf_audio_disguised.wav'] as $file) {
            $path = codecept_data_dir() . $file;

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Removes the private job directories these uploads created.
     *
     * The application would collect them anyway — the worker's orphan sweep exists precisely for
     * directories whose job row has gone — but it only touches directories older than the stale window,
     * so without this a test run leaves recordings on disk for ten minutes. A suite should not depend on
     * a background process to clean up after it.
     */
    private function removeJobDirectories(): void
    {
        $jobs = dirname(__DIR__, 2) . '/runtime/audio-to-text/jobs';

        if (!is_dir($jobs)) {
            return;
        }

        foreach ((array) glob($jobs . '/*', GLOB_ONLYDIR) as $directory) {
            // Only ever a 32-hex directory directly under jobs/, matching the application's own guard.
            if (preg_match('/^[0-9a-f]{32}$/', basename((string) $directory)) !== 1) {
                continue;
            }

            foreach ((array) glob($directory . '/*') as $file) {
                @unlink((string) $file);
            }

            @rmdir((string) $directory);
        }
    }

    private function writeFixtures(): void
    {
        // A genuine RIFF/WAVE file with a second of silence: real enough for libmagic and for ffprobe,
        // small enough to build inline rather than check a binary into the repository.
        $samples = str_repeat(pack('v', 0), 8000);
        $data = 'data' . pack('V', strlen($samples)) . $samples;
        $fmt = 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
            . pack('V', 8000) . pack('V', 16000) . pack('v', 2) . pack('v', 16);
        $body = 'WAVE' . $fmt . $data;

        file_put_contents(codecept_data_dir('kf_audio_valid.wav'), 'RIFF' . pack('V', strlen($body)) . $body);
        file_put_contents(codecept_data_dir('kf_audio_fake.txt'), "not audio\n");
        file_put_contents(codecept_data_dir('kf_audio_disguised.wav'), "<?php echo 'not audio'; ?>\n");
    }
}
