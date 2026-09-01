<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Domain\ReviewOperation;
use App\AudioToText\Infrastructure\DbSegmentRevisionRepository;
use App\AudioToText\Infrastructure\DbTranscriptionJobRepository;
use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function bin2hex;
use function json_encode;
use function random_bytes;

/**
 * The reviewed layer's persistence, against real MySQL.
 *
 * The assertion that matters most is {@see testCorrectionsNeverTouchTheMachineResult}: the whole design
 * rests on the machine's output being immutable, and "we were careful not to write it" is worth far less
 * than a test that reads the raw columns back and compares them byte for byte.
 *
 * **Never calls `claimNextQueued()`** — that takes the oldest QUEUED row in the entire table and can
 * strand a real administrator's pending upload, which has happened on this database. Rows here are
 * inserted with explicit statuses and removed by their own public id afterwards; nothing is ever
 * deleted in bulk.
 */
final class ReviewedConversationPersistenceTest extends Unit
{
    private const RAW_TRANSCRIPT = 'Yes. For pikup';

    private ConnectionInterface $connection;
    private DbTranscriptionJobRepository $jobs;
    private DbSegmentRevisionRepository $revisions;
    private int $adminId;

    /** @var list<string> */
    private array $createdPublicIds = [];

    /** @var list<string> */
    private array $createdUsernames = [];

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $clock = new SystemClock();
        $this->jobs = new DbTranscriptionJobRepository($this->connection, $clock);
        $this->revisions = new DbSegmentRevisionRepository($this->connection, $clock);

        $username = '__kf_review_' . bin2hex(random_bytes(4)) . '__';
        $this->createdUsernames[] = $username;
        $this->adminId = (new DbAdminUserRepository($this->connection, $clock))
            ->create($username, '$2y$10$notarealhashnotarealhashnotarealhashnotarealhashnotar');
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

    public function testAFreshJobHasNoReviewedLayer(): void
    {
        $job = $this->jobs->findByPublicId($this->seed());

        $this->assertNotNull($job);
        $this->assertFalse($job->isReviewed());
        $this->assertNull($job->reviewedSegmentsJson);
        $this->assertNull($job->reviewedAt);
        $this->assertNull($job->reviewedByUsername);
        $this->assertSame(0, $job->reviewCount);
    }

    public function testSavingAReviewStoresItAndRecordsWhoAndWhen(): void
    {
        $publicId = $this->seed();
        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);

        $applied = $this->jobs->saveReview(
            $job->id,
            $this->segments('Yes. For pickup'),
            'For pickup',
            'Yes.',
            $this->adminId,
            $job->reviewCount,
        );

        $this->assertTrue($applied);

        $reviewed = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($reviewed);
        $this->assertTrue($reviewed->isReviewed());
        $this->assertStringContainsString('For pickup', (string) $reviewed->reviewedSegmentsJson);
        $this->assertSame('For pickup', $reviewed->reviewedAgentText);
        $this->assertSame('Yes.', $reviewed->reviewedCustomerText);
        $this->assertNotNull($reviewed->reviewedAt);
        $this->assertSame($this->createdUsernames[0], $reviewed->reviewedByUsername);
        $this->assertSame(1, $reviewed->reviewCount);
    }

    /**
     * The guarantee the whole design rests on.
     *
     * A reviewed text correction changes "pikup" to "pickup" for the reader, and the machine's own
     * transcription still says "pikup" afterwards — so the pipeline remains auditable against what it
     * actually produced.
     */
    public function testCorrectionsNeverTouchTheMachineResult(): void
    {
        $publicId = $this->seed();
        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);

        $rawBefore = $this->rawColumns($publicId);

        $this->jobs->saveReview(
            $job->id,
            $this->segments('Yes. For pickup'),
            'For pickup',
            'Yes.',
            $this->adminId,
            $job->reviewCount,
        );

        $rawAfter = $this->rawColumns($publicId);

        $this->assertSame($rawBefore, $rawAfter, 'A correction must not alter any machine-written column.');
        $this->assertSame(self::RAW_TRANSCRIPT, $rawAfter['transcript'], 'The misheard word survives.');

        $reviewed = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($reviewed);
        $this->assertSame(self::RAW_TRANSCRIPT, $reviewed->transcript);
        $this->assertStringContainsString('For pickup', (string) $reviewed->reviewedSegmentsJson);
    }

    /** Two administrators in two tabs must not silently overwrite each other. */
    public function testAStaleVersionIsRefused(): void
    {
        $publicId = $this->seed();
        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);

        $staleVersion = $job->reviewCount;

        $this->assertTrue(
            $this->jobs->saveReview($job->id, $this->segments('first'), 'a', 'b', $this->adminId, $staleVersion),
        );

        // The second tab still believes the version it read on load.
        $this->assertFalse(
            $this->jobs->saveReview($job->id, $this->segments('second'), 'c', 'd', $this->adminId, $staleVersion),
            'A save carrying a superseded version must be refused.',
        );

        $current = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($current);
        $this->assertStringContainsString('first', (string) $current->reviewedSegmentsJson);
        $this->assertStringNotContainsString('second', (string) $current->reviewedSegmentsJson);
    }

    public function testRevertingDropsTheLayerButNotTheRawResult(): void
    {
        $publicId = $this->seed();
        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);

        $this->jobs->saveReview($job->id, $this->segments('corrected'), 'a', 'b', $this->adminId, $job->reviewCount);

        $reviewed = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($reviewed);
        $this->assertTrue($this->jobs->clearReview($reviewed->id, $this->adminId, $reviewed->reviewCount));

        $reverted = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($reverted);
        $this->assertFalse($reverted->isReviewed());
        $this->assertNull($reverted->reviewedAgentText);
        // The version still advances: a revert is a correction, not an undo of the counter.
        $this->assertSame(2, $reverted->reviewCount);
        $this->assertSame(self::RAW_TRANSCRIPT, $reverted->transcript);
    }

    public function testRevisionsRecordThePriorStateOperationAndAuthor(): void
    {
        $publicId = $this->seed();
        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);

        // The first correction's "prior state" is a copy of the machine's own segments.
        $first = $this->revisions->add(
            $job->id,
            (string) $job->speakerSegmentsJson,
            ReviewOperation::EditText,
            $this->adminId,
        );
        $second = $this->revisions->add(
            $job->id,
            $this->segments('Yes. For pickup'),
            ReviewOperation::Move,
            $this->adminId,
        );

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);

        $history = $this->revisions->forJob($job->id);

        $this->assertCount(2, $history);
        $this->assertSame(ReviewOperation::EditText, $history[0]->operation);
        $this->assertStringContainsString('pikup', $history[0]->segmentsJson, 'The prior wording is recoverable.');
        $this->assertSame(ReviewOperation::Move, $history[1]->operation);
        $this->assertSame('admin', $history[1]->editedByType);
        $this->assertSame($this->createdUsernames[0], $history[1]->editedByUsername);
        $this->assertSame($this->adminId, $history[1]->editedById);
    }

    /** The audit trail is append-only, and the database enforces the numbering. */
    public function testRevisionNumbersCannotCollide(): void
    {
        $publicId = $this->seed();
        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);

        $this->revisions->add($job->id, '[]', ReviewOperation::Move, $this->adminId);

        $rejected = false;

        try {
            // Re-inserting the same number is what two simultaneous corrections would attempt.
            $this->connection->createCommand()->insert('{{%audio_segment_revisions}}', [
                'job_id' => $job->id,
                'revision_number' => 1,
                'segments_json' => '[]',
                'operation' => ReviewOperation::Move->value,
                'edited_by_type' => 'admin',
                'edited_by_id' => $this->adminId,
                'created_at' => '2026-08-31 12:00:00',
            ])->execute();
        } catch (Throwable) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The unique index must refuse a duplicate revision number.');
    }

    /** The CHECK constraint is the reason the enum cannot quietly grow a case. */
    public function testAnUnknownOperationIsRefusedByTheDatabase(): void
    {
        $publicId = $this->seed();
        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);

        $rejected = false;

        try {
            $this->connection->createCommand()->insert('{{%audio_segment_revisions}}', [
                'job_id' => $job->id,
                'revision_number' => 1,
                'segments_json' => '[]',
                'operation' => 'DELETE_EVERYTHING',
                'edited_by_type' => 'admin',
                'edited_by_id' => $this->adminId,
                'created_at' => '2026-08-31 12:00:00',
            ])->execute();
        } catch (Throwable) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
    }

    /** Revisions describe a job and are meaningless without it. */
    public function testRevisionsCascadeWithTheirJob(): void
    {
        $publicId = $this->seed();
        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);

        $this->revisions->add($job->id, '[]', ReviewOperation::Move, $this->adminId);
        $this->assertSame(1, $this->revisions->countForJob($job->id));

        IntegrationDb::cleanup($this->connection, '{{%audio_transcription_jobs}}', ['public_id' => $publicId]);

        $this->assertSame(0, $this->revisions->countForJob($job->id));
    }

    /**
     * @return array<string, mixed> the machine-written columns, exactly as stored
     */
    private function rawColumns(string $publicId): array
    {
        /** @var array<string, mixed> $row */
        $row = (array) (new Query($this->connection))
            ->select(['transcript', 'speaker_segments', 'agent_text', 'customer_text'])
            ->from('{{%audio_transcription_jobs}}')
            ->where(['public_id' => $publicId])
            ->one();

        return $row;
    }

    private function segments(string $text): string
    {
        return (string) json_encode([[
            'start_ms' => 0,
            'end_ms' => 2000,
            'speaker' => 'SPEAKER_00',
            'role' => 'CUSTOMER',
            'text' => $text,
            'confidence' => 0.9,
            // Markers the existing decoder ignores; carried here to prove the column round-trips them.
            'edited' => true,
        ]]);
    }

    private function seed(): string
    {
        $publicId = bin2hex(random_bytes(16));
        $this->createdPublicIds[] = $publicId;

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => $publicId,
            'uploaded_by_admin_id' => $this->adminId,
            'status' => 'COMPLETED',
            'processing_stage' => 'COMPLETED',
            'original_filename' => 'review-fixture.wav',
            'transcript' => self::RAW_TRANSCRIPT,
            'agent_text' => 'For pikup',
            'customer_text' => 'Yes.',
            'speaker_segments' => $this->segments(self::RAW_TRANSCRIPT),
            'speaker_separation_status' => 'COMPLETED',
            'created_at' => '2026-08-31 10:00:00',
            'completed_at' => '2026-08-31 10:05:00',
        ])->execute();

        return $publicId;
    }
}
