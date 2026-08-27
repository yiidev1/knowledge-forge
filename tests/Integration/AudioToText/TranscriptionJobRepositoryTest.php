<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\ProcessingStage;
use App\AudioToText\Domain\Speaker\SpeakerSeparatedTranscript;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use App\AudioToText\Infrastructure\DbTranscriptionJobRepository;
use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Environment;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DbConnectionFactory;
use App\Shared\Infrastructure\Db\DbParams;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_filter;
use function bin2hex;
use function mb_strlen;
use function random_bytes;
use function str_repeat;

/**
 * Against real MySQL, because the guarantees under test are the *database's*.
 *
 * The atomic claim and the per-administrator limit are enforced by an InnoDB row lock and a unique
 * index on a generated column. A test double would assert that the code calls the right methods; only
 * a real database can assert that two concurrent connections cannot both win.
 */
final class TranscriptionJobRepositoryTest extends Unit
{
    private ConnectionInterface $connection;
    private DbTranscriptionJobRepository $repository;
    private int $adminA;
    private int $adminB;

    /** @var list<string> */
    private array $createdPublicIds = [];

    /** @var list<string> */
    private array $createdUsernames = [];

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->repository = new DbTranscriptionJobRepository($this->connection, new SystemClock());

        $this->adminA = $this->createAdmin('a2t-test-a-');
        $this->adminB = $this->createAdmin('a2t-test-b-');
    }

    protected function _after(): void
    {
        foreach ($this->createdPublicIds as $publicId) {
            IntegrationDb::cleanup($this->connection, '{{%audio_transcription_jobs}}', ['public_id' => $publicId]);
        }

        foreach ($this->createdUsernames as $username) {
            IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => $username]);
        }

        $this->createdPublicIds = [];
        $this->createdUsernames = [];
    }

    public function testACreatedJobStartsQueued(): void
    {
        $job = $this->repository->findByPublicId($this->createJob($this->adminA));

        $this->assertNotNull($job);
        $this->assertSame(JobStatus::QUEUED, $job->status);
        $this->assertSame(ProcessingStage::QUEUED, $job->stage);
        $this->assertNull($job->transcript);
        $this->assertSame(SpeakerSeparationStatus::PENDING, $job->speakerSeparationStatus);
    }

    public function testClaimingMovesAJobToProcessing(): void
    {
        $publicId = $this->createJob($this->adminA);

        $claimed = $this->repository->claimNextQueued();

        $this->assertNotNull($claimed);
        $this->assertSame($publicId, $claimed->publicId);
        $this->assertSame(JobStatus::PROCESSING, $claimed->status);
        $this->assertSame(ProcessingStage::CLAIMED, $claimed->stage);
        $this->assertNotNull($claimed->startedAt);
    }

    /**
     * Two workers, two independent connections, one job. Exactly one may win — and the loser must not
     * throw, because it simply moves on to the next candidate.
     */
    public function testTheSameJobCannotBeClaimedTwice(): void
    {
        $this->createJob($this->adminA);

        $workerA = new DbTranscriptionJobRepository($this->freshConnection(), new SystemClock());
        $workerB = new DbTranscriptionJobRepository($this->freshConnection(), new SystemClock());

        $claims = [$workerA->claimNextQueued(), $workerB->claimNextQueued()];
        $winners = array_filter($claims);

        $this->assertCount(1, $winners, 'Exactly one worker may claim a given job.');
    }

    /**
     * The restriction that used to live here is gone.
     *
     * A generated column plus a unique index once made a second active job for one administrator a
     * database error. That enforced "one at a time" in the wrong place: it stopped people queueing
     * work, which is what a queue is for. Concurrency is the worker's business now.
     */
    public function testOneAdministratorMayQueueManyRecordings(): void
    {
        $first = $this->createJob($this->adminA);
        $second = $this->createJob($this->adminA);
        $third = $this->createJob($this->adminA);

        foreach ([$first, $second, $third] as $publicId) {
            $job = $this->repository->findByPublicId($publicId);

            self::assertNotNull($job, 'Every upload must produce its own job.');
            $this->assertSame(JobStatus::QUEUED, $job->status);
        }

        $this->assertSame(3, $this->repository->countActive());
    }

    /** Queueing more is still allowed while one of the administrator's own jobs is PROCESSING. */
    public function testMoreMayBeQueuedWhileAnEarlierJobIsProcessing(): void
    {
        $this->createJob($this->adminA);
        $claimed = $this->repository->claimNextQueued();
        self::assertNotNull($claimed);
        $this->assertSame(JobStatus::PROCESSING, $claimed->status);

        $queuedDuring = $this->createJob($this->adminA);

        $job = $this->repository->findByPublicId($queuedDuring);
        self::assertNotNull($job);
        $this->assertSame(JobStatus::QUEUED, $job->status);
    }

    /** Several administrators queueing at once is equally unremarkable. */
    public function testSeveralAdministratorsMayQueueSimultaneously(): void
    {
        $ids = [
            $this->createJob($this->adminA),
            $this->createJob($this->adminB),
            $this->createJob($this->adminA),
            $this->createJob($this->adminB),
        ];

        $listed = [];
        foreach ($this->repository->recent(50, 80) as $item) {
            $listed[] = $item->publicId;
        }

        foreach ($ids as $publicId) {
            $this->assertContains($publicId, $listed, 'Every job belongs in the shared list.');
        }
    }

    /**
     * FIFO: the oldest waiting job is always taken first.
     *
     * Ordered by `id`, which is monotonic, so a newer upload can never jump ahead of an older one —
     * however many arrive while the worker is busy.
     */
    public function testJobsAreClaimedOldestFirst(): void
    {
        $first = $this->createJob($this->adminA);
        $second = $this->createJob($this->adminB);
        $third = $this->createJob($this->adminA);

        $claimedOrder = [];

        foreach ([$first, $second, $third] as $_) {
            $claimed = $this->repository->claimNextQueued();
            self::assertNotNull($claimed);
            $claimedOrder[] = $claimed->publicId;

            // Finish it so the next claim moves on rather than re-reading a PROCESSING row.
            $this->repository->markFailed($claimed->id, 'The audio could not be transcribed.');
        }

        $this->assertSame([$first, $second, $third], $claimedOrder);
    }

    /**
     * A finished job is terminal, permanently.
     *
     * The claim is `UPDATE ... WHERE id = ? AND status = 'QUEUED'`, and the candidate scan filters on
     * QUEUED too, so nothing that has already run can be picked up again — no matter how many times
     * the worker ticks, and no matter what is queued alongside it.
     */
    public function testACompletedJobIsNeverClaimedAgain(): void
    {
        $completed = $this->createJob($this->adminA);
        $completedJob = $this->repository->findByPublicId($completed);
        self::assertNotNull($completedJob);

        $this->repository->markTranscribed($completedJob->id, 'Already transcribed.', 'en');
        $this->repository->markCompleted(
            $completedJob->id,
            SpeakerSeparatedTranscript::notSupported('disabled'),
            'source.wav',
        );

        $second = $this->createJob($this->adminA);
        $third = $this->createJob($this->adminA);

        $claimed = [];
        while (($job = $this->repository->claimNextQueued()) !== null) {
            $claimed[] = $job->publicId;
            $this->repository->markFailed($job->id, 'The audio could not be transcribed.');
        }

        $this->assertSame([$second, $third], $claimed);
        $this->assertNotContains($completed, $claimed, 'A COMPLETED job must never be reprocessed.');

        // And its results are untouched by the later work.
        $reloaded = $this->repository->findById($completedJob->id);
        self::assertNotNull($reloaded);
        $this->assertSame(JobStatus::COMPLETED, $reloaded->status);
        $this->assertSame('Already transcribed.', $reloaded->transcript);
    }

    /** FAILED is terminal too — nothing is silently retried. */
    public function testAFailedJobIsNeverClaimedAgain(): void
    {
        $failed = $this->createJob($this->adminA);
        $failedJob = $this->repository->findByPublicId($failed);
        self::assertNotNull($failedJob);
        $this->repository->markFailed($failedJob->id, 'The audio could not be transcribed.');

        $this->assertNull($this->repository->claimNextQueued(), 'Nothing is left to claim.');
    }

    /** The position shown on a job page matches the order the worker will actually take them in. */
    public function testQueuePositionFollowsClaimOrder(): void
    {
        $first = $this->repository->findByPublicId($this->createJob($this->adminA));
        $second = $this->repository->findByPublicId($this->createJob($this->adminA));
        $third = $this->repository->findByPublicId($this->createJob($this->adminB));
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotNull($third);

        $this->assertSame(1, $this->repository->queuePositionOf($first->id));
        $this->assertSame(2, $this->repository->queuePositionOf($second->id));
        $this->assertSame(3, $this->repository->queuePositionOf($third->id));

        // Once claimed it is no longer waiting, so it has no position.
        $this->repository->claimNextQueued();

        $this->assertNull($this->repository->queuePositionOf($first->id));
        $this->assertSame(1, $this->repository->queuePositionOf($second->id));
    }

    /**
     * The write that makes a crash during speaker separation survivable: the transcript lands while the
     * job is still PROCESSING, and the audio path is deliberately left alone because the workspace is
     * still needed by the diarizer.
     */
    public function testMarkTranscribedCommitsTheTranscriptWhileStillProcessing(): void
    {
        $job = $this->repository->findByPublicId($this->createJob($this->adminA));
        self::assertNotNull($job);
        $this->repository->claimNextQueued();

        $this->repository->markTranscribed($job->id, 'Hello? Yes, you want to place an order?', 'en');

        $reloaded = $this->repository->findById($job->id);
        self::assertNotNull($reloaded);

        $this->assertSame(JobStatus::PROCESSING, $reloaded->status);
        $this->assertSame('Hello? Yes, you want to place an order?', $reloaded->transcript);
        $this->assertSame('en', $reloaded->detectedLanguage);
        $this->assertSame(ProcessingStage::DIARIZING, $reloaded->stage);
        $this->assertNotNull($reloaded->storedAudioPath, 'The workspace is still needed by the diarizer.');
    }

    public function testCompletingStoresEverythingAndClearsTheAudioPath(): void
    {
        $job = $this->repository->findByPublicId($this->createJob($this->adminA));
        self::assertNotNull($job);

        $this->repository->markTranscribed($job->id, 'Full transcript here.', 'en');
        $this->repository->markCompleted($job->id, SpeakerSeparatedTranscript::completed(
            "Pickup or delivery?",
            "Delivery please.",
            [new SpeakerUtterance(0, 1000, 'SPEAKER_00', SpeakerRole::AGENT, 'Pickup or delivery?', 1.0)],
            0.82,
            'sherpa-onnx',
        ), 'source.wav');

        $reloaded = $this->repository->findById($job->id);
        self::assertNotNull($reloaded);

        $this->assertSame(JobStatus::COMPLETED, $reloaded->status);
        $this->assertSame('Full transcript here.', $reloaded->transcript);
        $this->assertSame('Pickup or delivery?', $reloaded->agentText);
        $this->assertSame('Delivery please.', $reloaded->customerText);
        $this->assertSame(SpeakerSeparationStatus::COMPLETED, $reloaded->speakerSeparationStatus);
        $this->assertSame(0.82, $reloaded->speakerRoleConfidence);
        $this->assertNotNull($reloaded->speakerSegmentsJson);
        // The temporary column is cleared, but the recording itself is retained and recorded.
        $this->assertNull($reloaded->storedAudioPath);
        $this->assertSame('source.wav', $reloaded->retainedAudioPath);
        $this->assertTrue($reloaded->hasRetainedRecording());
    }

    /**
     * A failed job stores only wording written for the person who uploaded it. Exit codes, stderr and
     * filesystem paths go to the log and must never reach this column.
     */
    public function testFailingStoresOnlyTheUserFacingMessage(): void
    {
        $job = $this->repository->findByPublicId($this->createJob($this->adminA));
        self::assertNotNull($job);

        $this->repository->markFailed($job->id, 'The audio could not be transcribed.');

        $reloaded = $this->repository->findById($job->id);
        self::assertNotNull($reloaded);

        $this->assertSame(JobStatus::FAILED, $reloaded->status);
        $this->assertSame('The audio could not be transcribed.', $reloaded->errorMessage);
        $this->assertNull($reloaded->storedAudioPath);
    }

    /**
     * Crash recovery with a surviving transcript: the job completes, the transcript is untouched, the
     * split is marked failed, and `error_message` stays null because the job genuinely succeeded.
     */
    public function testCompletingWithoutSeparationPreservesTheTranscript(): void
    {
        $job = $this->repository->findByPublicId($this->createJob($this->adminA));
        self::assertNotNull($job);

        $this->repository->markTranscribed($job->id, 'A transcript that survived a crash.', 'en');
        $this->repository->markCompletedWithoutSeparation($job->id, SpeakerSeparationStatus::FAILED);

        $reloaded = $this->repository->findById($job->id);
        self::assertNotNull($reloaded);

        $this->assertSame(JobStatus::COMPLETED, $reloaded->status);
        $this->assertSame('A transcript that survived a crash.', $reloaded->transcript);
        $this->assertSame(SpeakerSeparationStatus::FAILED, $reloaded->speakerSeparationStatus);
        $this->assertNull($reloaded->errorMessage, 'The job succeeded; only the optional stage did not.');
        $this->assertNull($reloaded->agentText);
    }

    public function testStageTransitionsAreRecorded(): void
    {
        $job = $this->repository->findByPublicId($this->createJob($this->adminA));
        self::assertNotNull($job);

        foreach ([ProcessingStage::CONVERTING, ProcessingStage::TRANSCRIBING, ProcessingStage::SAVING] as $stage) {
            $this->repository->markStage($job->id, $stage);

            $reloaded = $this->repository->findById($job->id);
            self::assertNotNull($reloaded);
            $this->assertSame($stage, $reloaded->stage);
        }
    }

    /**
     * The list is global: this is a shared administrator demo, and the uploader is a column rather than
     * a filter.
     */
    public function testTheListIsGlobalAndNamesEachUploader(): void
    {
        $fromA = $this->createJob($this->adminA);
        $fromB = $this->createJob($this->adminB);

        $items = $this->repository->recent(50, 80);

        $byPublicId = [];
        foreach ($items as $item) {
            $byPublicId[$item->publicId] = $item;
        }

        $this->assertArrayHasKey($fromA, $byPublicId, "Admin A's job must appear in the global list.");
        $this->assertArrayHasKey($fromB, $byPublicId, "Admin B's job must appear in the global list.");
        $this->assertNotSame(
            $byPublicId[$fromA]->uploadedByUsername,
            $byPublicId[$fromB]->uploadedByUsername,
        );
    }

    /** Previews are truncated in SQL; a whole transcript never crosses the wire for the list view. */
    public function testTheListReturnsTruncatedPreviews(): void
    {
        $job = $this->repository->findByPublicId($this->createJob($this->adminA));
        self::assertNotNull($job);

        $long = str_repeat('The customer ordered chicken wings with tostones. ', 40);
        $this->repository->markTranscribed($job->id, $long, 'en');
        $this->repository->markCompleted($job->id, SpeakerSeparatedTranscript::notSupported('disabled'));

        $items = $this->repository->recent(50, 80);

        foreach ($items as $item) {
            if ($item->publicId === $job->publicId) {
                $this->assertNotNull($item->transcriptPreview);
                $this->assertLessThan(mb_strlen($long), mb_strlen($item->transcriptPreview));
                $this->assertTrue($item->downloadable);

                return;
            }
        }

        self::fail('The job did not appear in the list.');
    }

    public function testTheSummaryCountsByStatus(): void
    {
        $this->createJob($this->adminA);
        $second = $this->repository->findByPublicId($this->createJob($this->adminB));
        self::assertNotNull($second);
        $this->repository->markFailed($second->id, 'The audio could not be transcribed.');

        $summary = $this->repository->summary(new DateTimeImmutable('-24 hours', new DateTimeZone('UTC')));

        $this->assertGreaterThanOrEqual(1, $summary->queued);
        $this->assertGreaterThanOrEqual(1, $summary->failedLast24h);
        $this->assertTrue($summary->hasActive());
    }

    /** The enqueue lock serialises, and — critically — is always released, even when the work throws. */
    public function testTheEnqueueLockIsReleasedEvenWhenTheCallbackThrows(): void
    {
        try {
            $this->repository->enqueueExclusively(static function (): string {
                throw new RuntimeException('deliberate');
            });
            self::fail('The exception should have propagated.');
        } catch (RuntimeException) {
            // Expected.
        }

        // A leaked lock would make this second acquisition return null instead of running the callback.
        $result = $this->repository->enqueueExclusively(static fn(): string => 'acquired');

        $this->assertSame('acquired', $result);
    }

    public function testStaleJobsAreFoundOnlyAfterTheWindow(): void
    {
        $job = $this->repository->findByPublicId($this->createJob($this->adminA));
        self::assertNotNull($job);
        $this->repository->claimNextQueued();

        $this->assertSame([], $this->repository->findStale(600), 'A just-claimed job is not stale.');

        $this->connection->createCommand(
            'UPDATE {{%audio_transcription_jobs}} SET started_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE id = :id',
            [':id' => $job->id],
        )->execute();

        $stale = $this->repository->findStale(600);

        $this->assertCount(1, $stale);
        $this->assertSame($job->publicId, $stale[0]->publicId);
    }

    /** Only terminal jobs expire: deleting an active row would strand its audio directory. */
    public function testOnlyTerminalJobsExpire(): void
    {
        $job = $this->repository->findByPublicId($this->createJob($this->adminA, expiresAt: '-1 hour'));
        self::assertNotNull($job);

        $this->assertSame([], $this->repository->findExpired(), 'An active job never expires.');

        $this->repository->markFailed($job->id, 'The audio could not be transcribed.');

        $expired = $this->repository->findExpired();

        $this->assertNotSame([], $expired);
    }

    /**
     * The behaviour this project depends on: with retention disabled a completed conversation is never
     * offered up for deletion, however old it gets.
     */
    public function testAJobKeptIndefinitelyIsNeverExpired(): void
    {
        $job = $this->repository->findByPublicId($this->createJob($this->adminA));
        self::assertNotNull($job);
        self::assertTrue($job->isKeptIndefinitely(), 'The default must be indefinite retention.');

        $this->repository->markTranscribed($job->id, 'A conversation worth keeping.', 'en');
        $this->repository->markCompleted(
            $job->id,
            SpeakerSeparatedTranscript::notSupported('disabled'),
            'source.wav',
        );

        foreach ($this->repository->findExpired(100) as $candidate) {
            $this->assertNotSame(
                $job->publicId,
                $candidate->publicId,
                'A job kept indefinitely must never appear in the purge list.',
            );
        }

        $reloaded = $this->repository->findById($job->id);
        self::assertNotNull($reloaded);
        $this->assertNull($reloaded->expiresAt);
        $this->assertSame('A conversation worth keeping.', $reloaded->transcript);
        $this->assertSame('source.wav', $reloaded->retainedAudioPath);
    }

    /** A configured window still works — retention is a setting, not a code change. */
    public function testAConfiguredRetentionWindowStillExpires(): void
    {
        $job = $this->repository->findByPublicId($this->createJob($this->adminA, expiresAt: '-1 hour'));
        self::assertNotNull($job);

        $this->repository->markTranscribed($job->id, 'Old conversation.', 'en');
        $this->repository->markCompleted($job->id, SpeakerSeparatedTranscript::notSupported('disabled'), 'source.wav');

        $publicIds = [];
        foreach ($this->repository->findExpired(100) as $candidate) {
            $publicIds[] = $candidate->publicId;
        }

        $this->assertContains($job->publicId, $publicIds);
    }

    public function testActivePublicIdsFeedTheOrphanSweep(): void
    {
        $publicId = $this->createJob($this->adminA);

        $this->assertContains($publicId, $this->repository->activePublicIds());
    }

    private function createJob(int $adminId, ?string $expiresAt = null): string
    {
        $publicId = bin2hex(random_bytes(16));
        $this->createdPublicIds[] = $publicId;

        $this->repository->create(
            $publicId,
            $adminId,
            'call.wav',
            'source.wav',
            73.72,
            // Null is the project default: kept indefinitely.
            $expiresAt === null ? null : new DateTimeImmutable($expiresAt, new DateTimeZone('UTC')),
        );

        return $publicId;
    }

    private function createAdmin(string $prefix): int
    {
        $username = $prefix . bin2hex(random_bytes(4));
        $this->createdUsernames[] = $username;

        return (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create($username, '$2y$10$notarealhashnotarealhashnotarealhashnotarealhashnotar');
    }

    /**
     * A genuinely separate connection, built through the production factory.
     *
     * Not `IntegrationDb::connectOrSkip()`, which caches: two repositories sharing one session would
     * serialise inside PDO and the claim race would never actually occur. The point of the test is that
     * InnoDB arbitrates between two real sessions.
     */
    private function freshConnection(): ConnectionInterface
    {
        $params = new DbParams(
            host: Environment::string('DB_HOST'),
            port: Environment::int('DB_PORT'),
            name: Environment::string('DB_NAME'),
            user: Environment::string('DB_USER'),
            password: Environment::string('DB_PASSWORD'),
            charset: Environment::string('DB_CHARSET'),
            socket: Environment::string('DB_SOCKET'),
        );

        return (new DbConnectionFactory($params, new SchemaCache(new ArrayCache())))->create();
    }
}
