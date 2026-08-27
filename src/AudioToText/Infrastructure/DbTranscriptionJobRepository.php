<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Application\TranscriptText;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\ProcessingStage;
use App\AudioToText\Domain\QueueSummary;
use App\AudioToText\Domain\Speaker\SpeakerSeparatedTranscript;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobListItem;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Closure;
use DateTimeImmutable;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function is_array;
use function is_numeric;
use function is_object;

use const SORT_ASC;
use const SORT_DESC;

/**
 * MySQL-backed storage for transcription jobs.
 *
 * Two methods here are concurrency primitives rather than persistence, and they are the reason this
 * class is worth reading closely: {@see enqueueExclusively()} and {@see claimNextQueued()}.
 *
 * Note the absence of any uploader filter in the read methods. This is a shared administrator demo:
 * every authorized administrator sees every job, and `uploaded_by_admin_id` is audit metadata plus the
 * basis of the per-administrator enqueue limit — not an access-control key.
 */
final readonly class DbTranscriptionJobRepository implements TranscriptionJobRepositoryInterface
{
    private const TABLE = '{{%audio_transcription_jobs}}';
    private const ADMINS = '{{%admin_users}}';
    private const ENQUEUE_LOCK_TIMEOUT_SECONDS = 5;

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function findByPublicId(string $publicId): ?TranscriptionJob
    {
        return $this->hydrate($this->baseQuery()->where(['j.public_id' => $publicId])->limit(1)->one());
    }

    public function findById(int $id): ?TranscriptionJob
    {
        return $this->hydrate($this->baseQuery()->where(['j.id' => $id])->limit(1)->one());
    }

    /**
     * The global conversions list.
     *
     * Previews are truncated in SQL rather than in PHP. Fifty rows with three transcript columns each
     * would otherwise pull the entire text of every listed conversation across the wire so that the template
     * could throw away all but eighty characters of each.
     *
     * @return list<TranscriptionJobListItem>
     */
    public function recent(int $limit, int $previewLength, int $offset = 0): array
    {
        // The multiplier gives the word-boundary trim in TranscriptText::preview() something to work
        // with, while still keeping the transferred slice small and bounded.
        $sqlLength = max(1, $previewLength * 2);

        $rows = (new Query($this->connection))
            ->select([
                'j.public_id',
                'j.status',
                'j.processing_stage',
                'j.original_filename',
                'j.duration_seconds',
                'j.detected_language',
                'j.speaker_separation_status',
                'j.error_message',
                'j.created_at',
                // Aliases are deliberately unqualified: a key containing a dot is quoted as
                // `j`.`alias`, which MySQL rejects as an alias rather than reading as one.
                'transcript_is_null' => new Expression('(j.transcript IS NULL)'),
                'transcript_preview' => new Expression('LEFT(j.transcript, :len)'),
                'agent_preview' => new Expression('LEFT(j.agent_text, :len)'),
                'customer_preview' => new Expression('LEFT(j.customer_text, :len)'),
                'uploaded_by' => 'a.username',
            ])
            ->from(['j' => self::TABLE])
            ->leftJoin(['a' => self::ADMINS], 'a.id = j.uploaded_by_admin_id')
            ->addParams([':len' => $sqlLength])
            // By id, not created_at: two jobs enqueued in the same second need a stable order, and the
            // primary key is the only tiebreaker guaranteed to be unique.
            ->orderBy(['j.id' => SORT_DESC])
            ->limit($limit)
            // Paging on a strictly descending primary key. New uploads land at id N+1 and shift older
            // rows one place further back, so a row can in principle be seen twice across two page
            // loads while the queue is moving — the same trade every offset pager makes, and harmless
            // for a screen that already auto-refreshes while work is in flight.
            ->offset(max(0, $offset))
            ->all();

        $items = [];
        foreach ($rows as $row) {
            $row = (array) $row;

            $status = JobStatus::fromStorage($this->str($row['status'] ?? null));

            $items[] = new TranscriptionJobListItem(
                (string) ($row['public_id'] ?? ''),
                $this->str($row['uploaded_by'] ?? null),
                $status,
                ProcessingStage::fromStorage($this->str($row['processing_stage'] ?? null)),
                (string) ($row['original_filename'] ?? ''),
                $this->float($row['duration_seconds'] ?? null),
                $this->str($row['detected_language'] ?? null),
                TranscriptText::preview($this->str($row['transcript_preview'] ?? null), $previewLength),
                TranscriptText::preview($this->str($row['agent_preview'] ?? null), $previewLength),
                TranscriptText::preview($this->str($row['customer_preview'] ?? null), $previewLength),
                SpeakerSeparationStatus::fromStorage($this->str($row['speaker_separation_status'] ?? null)),
                $this->str($row['error_message'] ?? null),
                DbDateTime::parse((string) ($row['created_at'] ?? '')),
                $status === JobStatus::COMPLETED && (int) ($row['transcript_is_null'] ?? 1) === 0,
            );
        }

        return $items;
    }

    public function countAll(): int
    {
        return (int) (new Query($this->connection))->from(self::TABLE)->count();
    }

    /**
     * The four counters above both Audio-to-Text pages.
     *
     * Takes no cutoff. It used to, and both callers computed the same expression from
     * `AUDIO_TRANSCRIPTION_RETENTION_SECONDS` — which meant the window was wrong in two places at once
     * the moment retention was set to "keep indefinitely" (see {@see QueueSummary}). The window belongs
     * to the summary, not to whoever is rendering it, so it is derived here from a single constant and
     * the injected clock.
     *
     * **The terminal counters filter on `completed_at`, not `created_at`.** They answer "what finished
     * recently", and a job uploaded yesterday that finished ten minutes ago belongs in today's figures.
     * `created_at` was measuring when work *arrived*, which is a different question and not the one the
     * label asks. Every COMPLETED and FAILED row has `completed_at` set — the worker stamps it on both
     * terminal transitions — so nothing falls through the filter for want of a timestamp.
     *
     * **Job status only.** `speaker_separation_status` is deliberately not consulted: a transcription
     * that succeeded is a completed job whether or not the speaker split needs review, and those are
     * separate concerns tracked in separate columns.
     */
    public function summary(): QueueSummary
    {
        // UTC throughout: the clock returns UTC, the connection pins the session to +00:00, and rows
        // store UTC. The admin pages render local time, but that conversion happens in the template.
        $cutoff = DbDateTime::format(
            $this->clock->now()->modify('-' . QueueSummary::WINDOW_HOURS . ' hours'),
        );

        $row = (new Query($this->connection))
            ->select([
                'queued' => new Expression("SUM(status = 'QUEUED')"),
                'processing' => new Expression("SUM(status = 'PROCESSING')"),
                'completed' => new Expression("SUM(status = 'COMPLETED' AND completed_at >= :cutoff)"),
                'failed' => new Expression("SUM(status = 'FAILED' AND completed_at >= :cutoff)"),
            ])
            ->from(self::TABLE)
            ->addParams([':cutoff' => $cutoff])
            ->one();

        if (!is_array($row) && !is_object($row)) {
            return QueueSummary::empty();
        }

        $row = (array) $row;

        return new QueueSummary(
            (int) ($row['queued'] ?? 0),
            (int) ($row['processing'] ?? 0),
            (int) ($row['completed'] ?? 0),
            (int) ($row['failed'] ?? 0),
        );
    }

    public function countActive(): int
    {
        return (int) (new Query($this->connection))
            ->from(self::TABLE)
            ->where(['status' => JobStatus::activeValues()])
            ->count();
    }


    public function queuePositionOf(int $id): ?int
    {
        $ahead = (new Query($this->connection))
            ->from(self::TABLE)
            ->where(['status' => JobStatus::QUEUED->value])
            ->andWhere(['<', 'id', $id])
            ->count();

        // Only a job that is still waiting has a position. A claimed or finished job has none, and
        // saying "position 1" for something already running would be misleading.
        $waiting = (new Query($this->connection))
            ->from(self::TABLE)
            ->where(['id' => $id, 'status' => JobStatus::QUEUED->value])
            ->exists();

        return $waiting ? (int) $ahead + 1 : null;
    }

    public function existsByPublicId(string $publicId): bool
    {
        return (new Query($this->connection))
            ->from(self::TABLE)
            ->where(['public_id' => $publicId])
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function activePublicIds(): array
    {
        $rows = (new Query($this->connection))
            ->select('public_id')
            ->from(self::TABLE)
            ->where(['status' => JobStatus::activeValues()])
            ->column();

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (string) $row;
        }

        return $ids;
    }

    /**
     * Serialises the final admission decision with a MySQL named lock.
     *
     * `CONCAT(DATABASE(), …)` is not decoration. **MySQL named locks are server-global, not per-schema**,
     * so on a host running several applications an unprefixed name would let one project's uploads block
     * another's — which is exactly the kind of cross-tenant coupling nobody would think to look for.
     *
     * @param Closure(): string $work
     */
    public function enqueueExclusively(Closure $work): ?string
    {
        $lockName = $this->enqueueLockName();

        $acquired = $this->connection->createCommand(
            'SELECT GET_LOCK(:name, :timeout)',
            [':name' => $lockName, ':timeout' => self::ENQUEUE_LOCK_TIMEOUT_SECONDS],
        )->queryScalar();

        // 0 means the wait timed out; NULL means the lock could not be evaluated at all. Neither is a
        // reason to run the checks unserialised.
        if ((int) $acquired !== 1) {
            return null;
        }

        try {
            return $work();
        } finally {
            $this->connection->createCommand(
                'SELECT RELEASE_LOCK(:name)',
                [':name' => $lockName],
            )->execute();
        }
    }

    public function create(
        string $publicId,
        int $uploadedByAdminId,
        string $originalFilename,
        string $storedAudioPath,
        ?float $durationSeconds,
        ?DateTimeImmutable $expiresAt,
    ): string {
        $this->connection->createCommand()->insert(self::TABLE, [
            'public_id' => $publicId,
            'uploaded_by_admin_id' => $uploadedByAdminId,
            'status' => JobStatus::QUEUED->value,
            'processing_stage' => ProcessingStage::QUEUED->value,
            'original_filename' => $originalFilename,
            'stored_audio_path' => $storedAudioPath,
            'retained_audio_path' => null,
            'duration_seconds' => $durationSeconds,
            'transcript' => null,
            'detected_language' => null,
            'error_message' => null,
            'agent_text' => null,
            'customer_text' => null,
            'speaker_segments' => null,
            'speaker_separation_status' => SpeakerSeparationStatus::PENDING->value,
            'speaker_separation_method' => null,
            'speaker_role_confidence' => null,
            'speaker_separation_completed_at' => null,
            'created_at' => DbDateTime::format($this->clock->now()),
            'started_at' => null,
            'completed_at' => null,
            // NULL means kept indefinitely, which is the default. The purge query skips NULLs.
            'expires_at' => $expiresAt === null ? null : DbDateTime::format($expiresAt),
        ])->execute();

        return $publicId;
    }

    /**
     * The atomic claim.
     *
     * Two workers racing for the same row serialise on the InnoDB row lock; the loser's
     * `WHERE status = 'QUEUED'` no longer matches, it gets zero affected rows, and it moves on to the
     * next candidate. No transaction is opened and no `SELECT ... FOR UPDATE` is held, so a worker that
     * dies mid-claim blocks nobody and leaves nothing to clean up.
     *
     * This alone is not enough to keep CPU usage sane — it would happily let worker A take job 1 while
     * worker B takes job 2. The `flock` on `worker.lock` is what makes the limit global.
     */
    public function claimNextQueued(int $candidates = 10): ?TranscriptionJob
    {
        $ids = (new Query($this->connection))
            ->select('id')
            ->from(self::TABLE)
            ->where(['status' => JobStatus::QUEUED->value])
            ->orderBy(['id' => SORT_ASC])
            ->limit($candidates)
            ->column();

        foreach ($ids as $id) {
            // PDO hands back int or numeric string depending on how warm the schema cache is.
            $id = (int) $id;

            $affected = $this->connection->createCommand()->update(
                self::TABLE,
                [
                    'status' => JobStatus::PROCESSING->value,
                    'processing_stage' => ProcessingStage::CLAIMED->value,
                    'started_at' => DbDateTime::format($this->clock->now()),
                ],
                ['id' => $id, 'status' => JobStatus::QUEUED->value],
            )->execute();

            if ($affected === 1) {
                return $this->findById($id);
            }
        }

        return null;
    }

    /**
     * Best-effort telemetry. A failure to record a stage must never fail a job that is otherwise
     * progressing perfectly well.
     */
    public function markStage(int $id, ProcessingStage $stage): void
    {
        try {
            $this->connection->createCommand()
                ->update(self::TABLE, ['processing_stage' => $stage->value], ['id' => $id])
                ->execute();
        } catch (Throwable) {
            // Intentionally swallowed; the caller logs.
        }
    }

    /**
     * Commits the transcript the instant Whisper succeeds, while the job is still PROCESSING.
     *
     * This single write is what makes a crash during speaker separation survivable. Recovery can see a
     * non-null transcript and complete the job rather than throwing away ninety-four seconds of CPU
     * because a later, optional stage died. `stored_audio_path` is deliberately left alone: the
     * workspace is still needed by the diarizer.
     */
    public function markTranscribed(int $id, string $transcript, ?string $detectedLanguage): void
    {
        $this->connection->createCommand()->update(self::TABLE, [
            'transcript' => $transcript,
            'detected_language' => $detectedLanguage,
            'processing_stage' => ProcessingStage::DIARIZING->value,
            'speaker_separation_status' => SpeakerSeparationStatus::PROCESSING->value,
        ], ['id' => $id])->execute();
    }

    public function markCompleted(
        int $id,
        SpeakerSeparatedTranscript $separation,
        ?string $retainedAudioPath = null,
    ): void {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->update(self::TABLE, [
            'status' => JobStatus::COMPLETED->value,
            'processing_stage' => ProcessingStage::COMPLETED->value,
            'agent_text' => $separation->agentText,
            'customer_text' => $separation->customerText,
            'speaker_segments' => $separation->segmentsJson(),
            'speaker_separation_status' => $separation->status->value,
            'speaker_separation_method' => $separation->method,
            'speaker_role_confidence' => $separation->confidence,
            'speaker_separation_completed_at' => $now,
            // The recording has moved out of the temporary workspace into permanent storage, so the
            // temporary column is cleared and the retained one takes over. The file itself is kept.
            'stored_audio_path' => null,
            'retained_audio_path' => $retainedAudioPath,
            'completed_at' => $now,
        ], ['id' => $id])->execute();
    }

    /**
     * Completes a job whose transcript survived but whose speaker separation did not — the crash-recovery
     * counterpart to {@see markCompleted()}.
     *
     * `error_message` is deliberately left null. The job succeeded: it produced the transcript that was
     * asked for. Only the optional second stage did not, and that is what the separation status records.
     */
    public function markCompletedWithoutSeparation(int $id, SpeakerSeparationStatus $status): void
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->update(self::TABLE, [
            'status' => JobStatus::COMPLETED->value,
            'processing_stage' => ProcessingStage::COMPLETED->value,
            'speaker_separation_status' => $status->value,
            'speaker_separation_completed_at' => $now,
            'stored_audio_path' => null,
            'completed_at' => $now,
        ], ['id' => $id])->execute();
    }

    public function markFailed(int $id, string $userMessage): void
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->update(self::TABLE, [
            'status' => JobStatus::FAILED->value,
            'processing_stage' => ProcessingStage::FAILED->value,
            // User-facing wording only. Exit codes, stderr and paths go to the log.
            'error_message' => $userMessage,
            'stored_audio_path' => null,
            'completed_at' => $now,
        ], ['id' => $id])->execute();
    }

    /**
     * @return list<TranscriptionJob>
     */
    public function findStale(int $staleAfterSeconds): array
    {
        $cutoff = DbDateTime::format(
            $this->clock->now()->modify('-' . $staleAfterSeconds . ' seconds'),
        );

        $rows = $this->baseQuery()
            ->where(['j.status' => JobStatus::PROCESSING->value])
            ->andWhere(['<', 'j.started_at', $cutoff])
            ->all();

        return $this->hydrateAll($rows);
    }

    /**
     * Only terminal jobs are eligible. Deleting an active row would strand its audio directory, which
     * the orphan sweep would then have to guess about.
     *
     * @return list<TranscriptionJob>
     */
    public function findExpired(int $limit = 100): array
    {
        $now = DbDateTime::format($this->clock->now());

        $rows = $this->baseQuery()
            ->where(['j.status' => JobStatus::terminalValues()])
            // NULL is "keep indefinitely" and must never match. `<=` alone would already exclude NULL in
            // SQL, but saying so explicitly means the intent survives someone editing this clause.
            ->andWhere(['IS NOT', 'j.expires_at', null])
            ->andWhere(['<=', 'j.expires_at', $now])
            ->limit($limit)
            ->all();

        return $this->hydrateAll($rows);
    }

    public function delete(int $id): void
    {
        $this->connection->createCommand()->delete(self::TABLE, ['id' => $id])->execute();
    }

    private function baseQuery(): Query
    {
        return (new Query($this->connection))
            ->select(['j.*', 'uploaded_by' => 'a.username'])
            ->from(['j' => self::TABLE])
            ->leftJoin(['a' => self::ADMINS], 'a.id = j.uploaded_by_admin_id');
    }

    private function enqueueLockName(): string
    {
        try {
            $database = (string) $this->connection->createCommand('SELECT DATABASE()')->queryScalar();
        } catch (Throwable) {
            $database = '';
        }

        return ($database === '' ? 'app' : $database) . ':audio-to-text:enqueue';
    }

    /**
     * @param iterable<array-key, mixed> $rows
     *
     * @return list<TranscriptionJob>
     */
    private function hydrateAll(iterable $rows): array
    {
        $jobs = [];
        foreach ($rows as $row) {
            $job = $this->hydrate($row);
            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    private function hydrate(mixed $row): ?TranscriptionJob
    {
        if (!is_array($row) && !is_object($row)) {
            return null;
        }

        $row = (array) $row;
        if (($row['id'] ?? null) === null) {
            return null;
        }

        return new TranscriptionJob(
            (int) $row['id'],
            (string) ($row['public_id'] ?? ''),
            (int) ($row['uploaded_by_admin_id'] ?? 0),
            $this->str($row['uploaded_by'] ?? null),
            JobStatus::fromStorage($this->str($row['status'] ?? null)),
            ProcessingStage::fromStorage($this->str($row['processing_stage'] ?? null)),
            (string) ($row['original_filename'] ?? ''),
            $this->str($row['stored_audio_path'] ?? null),
            $this->str($row['retained_audio_path'] ?? null),
            $this->float($row['duration_seconds'] ?? null),
            $this->str($row['transcript'] ?? null),
            $this->str($row['detected_language'] ?? null),
            $this->str($row['error_message'] ?? null),
            $this->str($row['agent_text'] ?? null),
            $this->str($row['customer_text'] ?? null),
            $this->str($row['speaker_segments'] ?? null),
            SpeakerSeparationStatus::fromStorage($this->str($row['speaker_separation_status'] ?? null)),
            $this->str($row['speaker_separation_method'] ?? null),
            $this->float($row['speaker_role_confidence'] ?? null),
            DbDateTime::parse((string) ($row['created_at'] ?? '')),
            DbDateTime::parseNullable($this->str($row['started_at'] ?? null)),
            DbDateTime::parseNullable($this->str($row['completed_at'] ?? null)),
            DbDateTime::parseNullable($this->str($row['expires_at'] ?? null)),
        );
    }

    /** Rows arrive as strings under `PDO::ATTR_STRINGIFY_FETCHES`; null must survive as null. */
    private function str(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function float(mixed $value): ?float
    {
        return $value === null || !is_numeric($value) ? null : (float) $value;
    }
}
