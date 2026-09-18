<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure\Tts;

use App\AudioToText\Domain\Tts\TtsEnqueueOutcome;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsRendition;
use App\AudioToText\Domain\Tts\TtsRenditionRepositoryInterface;
use App\AudioToText\Domain\Tts\TtsStatus;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function bin2hex;
use function is_array;
use function is_numeric;
use function mb_substr;
use function random_bytes;

use const SORT_ASC;

/**
 * MySQL-backed storage for AI audio renditions.
 *
 * Most of this class is ordinary persistence. Four methods are not, and they are the ones worth reading
 * closely, because each exists to stop a specific way of paying Deepgram twice for one recording:
 * {@see enqueue()}, {@see claim()}, {@see markReady()} and {@see recoverStale()}.
 */
final readonly class DbTtsRenditionRepository implements TtsRenditionRepositoryInterface
{
    private const TABLE = '{{%audio_tts_renditions}}';
    private const JOBS = '{{%audio_transcription_jobs}}';
    private const ADMINS = '{{%admin_users}}';

    /** `error_message` is VARCHAR(255) and is rendered on a page; a longer message is cut, not refused. */
    private const MAX_ERROR_LENGTH = 255;

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function findForJob(int $jobId, TtsOutputType $outputType): ?TtsRendition
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->baseQuery()
            ->where(['r.job_id' => $jobId, 'r.output_type' => $outputType->value])
            ->one();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function forJobs(array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }

        $byJob = [];

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->baseQuery()->where(['r.job_id' => $jobIds])->all();

        foreach ($rows as $row) {
            $rendition = $this->hydrate($row);

            if ($rendition !== null) {
                $byJob[$rendition->jobId][$rendition->outputType->value] = $rendition;
            }
        }

        return $byJob;
    }

    /**
     * Queue a generation, unless one is already outstanding.
     *
     * ## Why this is not `INSERT … ON DUPLICATE KEY UPDATE status='QUEUED'`
     *
     * The unique key on `(job_id, output_type)` makes an upsert the obvious shape, and the obvious shape
     * is a bug. It would reset a row that a worker is **currently generating** back to QUEUED; the next
     * tick would then claim it, and two workers would be synthesising the same conversation at the same
     * time, both billing for it, both racing to write `file_name`. Nothing about the unique key prevents
     * that, because it is one row being written twice rather than two rows.
     *
     * So the update carries `status NOT IN ('QUEUED','GENERATING')` and the affected-row count is the
     * answer. A race between two callers resolves the same way: both attempt the insert, one gets a
     * duplicate-key error, and the conditional update decides between them.
     *
     * `attempt_token` is rotated on every successful queue. That is what makes a superseded attempt
     * harmless — see {@see markReady()}.
     */
    public function enqueue(
        int $jobId,
        TtsOutputType $outputType,
        string $requestedHash,
        ?int $adminId,
        string $provider,
    ): TtsEnqueueOutcome {
        $now = DbDateTime::format($this->clock->now());
        $token = bin2hex(random_bytes(16));

        try {
            $this->connection->createCommand()->insert(self::TABLE, [
                'job_id' => $jobId,
                'output_type' => $outputType->value,
                'status' => TtsStatus::Queued->value,
                'attempt_token' => $token,
                'requested_hash' => $requestedHash,
                'provider' => $provider,
                'requested_by_admin_id' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();

            return TtsEnqueueOutcome::Queued;
        } catch (Throwable) {
            // The unique key fired: a rendition for this (job, type) already exists. Whether it may be
            // re-queued is decided below, in a statement the database evaluates rather than this process.
        }

        $affected = $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => TtsStatus::Queued->value,
                'attempt_token' => $token,
                'requested_hash' => $requestedHash,
                'requested_by_admin_id' => $adminId,
                'error_message' => null,
                'generating_since' => null,
                'updated_at' => $now,
            ],
            [
                'and',
                ['job_id' => $jobId, 'output_type' => $outputType->value],
                // The whole guard. A row mid-flight is left exactly as it is.
                ['not in', 'status', [TtsStatus::Queued->value, TtsStatus::Generating->value]],
            ],
        )->execute();

        if ($affected === 1) {
            return TtsEnqueueOutcome::Queued;
        }

        // Nothing was written. Either an attempt is in flight, or the row vanished under us — and the
        // two read very differently to somebody who just pressed a button, so they are told apart.
        return $this->findForJob($jobId, $outputType) === null
            ? TtsEnqueueOutcome::NotFound
            : TtsEnqueueOutcome::AlreadyRunning;
    }

    /**
     * Take the oldest queued rendition, atomically.
     *
     * The same shape as `DbTranscriptionJobRepository::claimNextQueued()`: a cheap list of candidates,
     * then a conditional update per candidate whose affected-row count decides the winner. Two workers
     * running simultaneously produce one claim and one null — never two claims — because the condition
     * and the write are the same statement.
     *
     * Walking a short list rather than taking only the head means one rendition that somehow cannot be
     * claimed does not stall everything behind it.
     */
    public function claim(int $candidates = 10): ?TtsRendition
    {
        $ids = (new Query($this->connection))
            ->select('id')
            ->from(self::TABLE)
            ->where(['status' => TtsStatus::Queued->value])
            ->orderBy(['id' => SORT_ASC])
            ->limit($candidates)
            ->column();

        $now = DbDateTime::format($this->clock->now());

        foreach ($ids as $id) {
            // PDO hands back int or numeric string depending on how warm the schema cache is.
            $id = (int) $id;

            $affected = $this->connection->createCommand()->update(
                self::TABLE,
                [
                    'status' => TtsStatus::Generating->value,
                    'generating_since' => $now,
                    'updated_at' => $now,
                    'attempts' => new Expression('`attempts` + 1'),
                ],
                ['id' => $id, 'status' => TtsStatus::Queued->value],
            )->execute();

            if ($affected === 1) {
                return $this->findById($id);
            }
        }

        return null;
    }

    /**
     * Publish a finished file — but only if this attempt is still the current one.
     *
     * The `attempt_token` comparison is what protects a newer result from an older worker. If somebody
     * re-queued this rendition while a slow generation was still running, the token has moved on, this
     * update affects nothing, and the caller is told so it can delete the file it just made rather than
     * leaving an orphan on disk pointing at nobody.
     */
    public function markReady(
        int $id,
        string $attemptToken,
        string $fileName,
        string $fileHash,
        string $fileRenderKey,
        int $fileBytes,
        int $characterCount,
        int $requestCount,
        ?string $modelCustomer,
        ?string $modelAgent,
    ): bool {
        $now = DbDateTime::format($this->clock->now());

        return 1 === $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => TtsStatus::Ready->value,
                'file_name' => $fileName,
                'file_hash' => $fileHash,
                'file_render_key' => $fileRenderKey,
                'file_bytes' => $fileBytes,
                'character_count' => $characterCount,
                'request_count' => $requestCount,
                'model_customer' => $modelCustomer,
                'model_agent' => $modelAgent,
                'error_message' => null,
                'generating_since' => null,
                'generated_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $id, 'attempt_token' => $attemptToken],
        )->execute();
    }

    /**
     * Record a failure, leaving any previous file exactly where it is.
     *
     * `file_name`, `file_hash`, `file_render_key` and `file_bytes` are conspicuously absent from this
     * update, and that absence is the feature: a regeneration that fails must leave the audio it was
     * trying to replace as playable as it was before anybody pressed the button.
     */
    public function markFailed(int $id, string $attemptToken, string $message): bool
    {
        $now = DbDateTime::format($this->clock->now());

        return 1 === $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => TtsStatus::Failed->value,
                'error_message' => mb_substr($message, 0, self::MAX_ERROR_LENGTH),
                'generating_since' => null,
                'updated_at' => $now,
            ],
            ['id' => $id, 'attempt_token' => $attemptToken],
        )->execute();
    }

    /**
     * Rescue rows abandoned mid-generation.
     *
     * Without this a worker killed by a deploy, the OOM killer or a systemd timeout leaves a row in
     * GENERATING permanently — and it is genuinely unreachable afterwards, because {@see claim()} only
     * looks at QUEUED and {@see enqueue()} correctly refuses anything in flight. The administrator would
     * see "Generating" forever with no button that could help.
     *
     * Marked FAILED, never retried. The transcription worker's stale sweep gives the reason — a crash
     * mid-run may well be reproduced by running it again — and here every repetition would also be
     * billed. A person decides whether another attempt is worth it.
     *
     * `attempt_token` is deliberately **not** rotated: a worker that turns out to still be alive and
     * finishes normally will then find its token intact and publish its file, which is the better
     * outcome than discarding audio that has already been paid for.
     */
    public function recoverStale(int $olderThanSeconds): int
    {
        $cutoff = DbDateTime::format(
            $this->clock->now()->modify('-' . $olderThanSeconds . ' seconds'),
        );

        return $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => TtsStatus::Failed->value,
                'error_message' => 'Generating this audio was interrupted and did not finish. '
                    . 'Any previous audio is still available.',
                'generating_since' => null,
                'updated_at' => DbDateTime::format($this->clock->now()),
            ],
            [
                'and',
                ['status' => TtsStatus::Generating->value],
                ['<', 'generating_since', $cutoff],
            ],
        )->execute();
    }

    public function generatingJobPublicIds(): array
    {
        $ids = (new Query($this->connection))
            ->select('j.public_id')
            ->from(['r' => self::TABLE])
            ->innerJoin(['j' => self::JOBS], 'j.id = r.job_id')
            ->where(['r.status' => TtsStatus::Generating->value])
            ->column();

        $publicIds = [];
        foreach ($ids as $id) {
            $publicIds[] = (string) $id;
        }

        return $publicIds;
    }

    public function countByStatus(TtsStatus $status): int
    {
        return (int) (new Query($this->connection))
            ->from(self::TABLE)
            ->where(['status' => $status->value])
            ->count();
    }

    private function findById(int $id): ?TtsRendition
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->baseQuery()->where(['r.id' => $id])->one();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * The username is joined rather than stored, so renaming an administrator does not leave a stale copy
     * on every rendition they ever asked for.
     */
    private function baseQuery(): Query
    {
        return (new Query($this->connection))
            ->select([
                'r.id',
                'r.job_id',
                'r.output_type',
                'r.status',
                'r.attempt_token',
                'r.requested_hash',
                'r.file_name',
                'r.file_hash',
                'r.file_render_key',
                'r.file_bytes',
                'r.character_count',
                'r.request_count',
                'r.provider',
                'r.model_customer',
                'r.model_agent',
                'r.attempts',
                'r.error_message',
                'r.requested_by_admin_id',
                'r.created_at',
                'r.updated_at',
                'r.generating_since',
                'r.generated_at',
                'requested_by_username' => 'a.username',
            ])
            ->from(['r' => self::TABLE])
            ->leftJoin(['a' => self::ADMINS], 'a.id = r.requested_by_admin_id');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ?TtsRendition
    {
        $outputType = TtsOutputType::fromStorage($this->stringOrNull($row['output_type'] ?? null));
        $status = TtsStatus::fromStorage($this->stringOrNull($row['status'] ?? null));

        // A row whose type or status does not decode is one this build does not understand. Skipped
        // rather than guessed at: inventing a status for it could present a half-known row as ready.
        if ($outputType === null || $status === null) {
            return null;
        }

        return new TtsRendition(
            (int) ($row['id'] ?? 0),
            (int) ($row['job_id'] ?? 0),
            $outputType,
            $status,
            (string) ($row['attempt_token'] ?? ''),
            (string) ($row['requested_hash'] ?? ''),
            $this->stringOrNull($row['file_name'] ?? null),
            $this->stringOrNull($row['file_hash'] ?? null),
            $this->stringOrNull($row['file_render_key'] ?? null),
            $this->intOrNull($row['file_bytes'] ?? null),
            $this->intOrNull($row['character_count'] ?? null),
            $this->intOrNull($row['request_count'] ?? null),
            (string) ($row['provider'] ?? ''),
            $this->stringOrNull($row['model_customer'] ?? null),
            $this->stringOrNull($row['model_agent'] ?? null),
            (int) ($row['attempts'] ?? 0),
            $this->stringOrNull($row['error_message'] ?? null),
            $this->intOrNull($row['requested_by_admin_id'] ?? null),
            $this->stringOrNull($row['requested_by_username'] ?? null),
            $this->dateTime($row['created_at'] ?? null),
            $this->dateTime($row['updated_at'] ?? null),
            $this->dateTimeOrNull($row['generating_since'] ?? null),
            $this->dateTimeOrNull($row['generated_at'] ?? null),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function dateTime(mixed $value): DateTimeImmutable
    {
        $parsed = $this->dateTimeOrNull($value);

        return $parsed ?? $this->clock->now();
    }

    private function dateTimeOrNull(mixed $value): ?DateTimeImmutable
    {
        $text = $this->stringOrNull($value);

        return $text === null ? null : DbDateTime::parseNullable($text);
    }
}
