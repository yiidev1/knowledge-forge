<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\ReviewOperation;
use App\AudioToText\Domain\SegmentRevision;
use App\AudioToText\Domain\SegmentRevisionRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_string;

use const SORT_ASC;

/**
 * Append-only audit trail for conversation corrections.
 *
 * No update, no delete: a history that can be rewritten is not a history. Reverting to the machine's
 * result is itself an entry rather than an erasure.
 *
 * Revision numbers are assigned per job as `MAX + 1`. Two simultaneous corrections would compute the
 * same number, and the unique index on `(job_id, revision_number)` rejects the loser — which is the
 * intended outcome, because the caller's optimistic lock on `review_count` will have refused that save
 * anyway. The index is the second lock, not the only one.
 */
final readonly class DbSegmentRevisionRepository implements SegmentRevisionRepositoryInterface
{
    private const TABLE = '{{%audio_segment_revisions}}';
    private const ADMINS = '{{%admin_users}}';
    private const PARTICIPANT_ADMIN = 'admin';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function add(
        int $jobId,
        string $priorSegmentsJson,
        ReviewOperation $operation,
        int $editedByAdminId,
    ): int {
        $next = $this->countForJob($jobId) + 1;

        $this->connection->createCommand()->insert(self::TABLE, [
            'job_id' => $jobId,
            'revision_number' => $next,
            'segments_json' => $priorSegmentsJson,
            'operation' => $operation->value,
            'edited_by_type' => self::PARTICIPANT_ADMIN,
            'edited_by_id' => $editedByAdminId,
            'created_at' => DbDateTime::format($this->clock->now()),
        ])->execute();

        return $next;
    }

    public function forJob(int $jobId): array
    {
        $rows = (new Query($this->connection))
            // The editor's name is joined for display; the numeric id stays server-side.
            ->select(['r.*', 'edited_by_username' => 'a.username'])
            ->from(['r' => self::TABLE])
            ->leftJoin(['a' => self::ADMINS], 'a.id = r.edited_by_id')
            ->where(['r.job_id' => $jobId])
            ->orderBy(['r.revision_number' => SORT_ASC])
            ->all();

        $revisions = [];
        foreach ($rows as $row) {
            $row = (array) $row;

            $operation = ReviewOperation::fromStorage(
                is_string($row['operation'] ?? null) ? (string) $row['operation'] : '',
            );

            if ($operation === null) {
                // A value the CHECK constraint should have refused. Skipping keeps one unreadable row
                // from hiding an otherwise intact history.
                continue;
            }

            $revisions[] = new SegmentRevision(
                (int) ($row['id'] ?? 0),
                (int) ($row['job_id'] ?? 0),
                (int) ($row['revision_number'] ?? 0),
                is_string($row['segments_json'] ?? null) ? (string) $row['segments_json'] : '[]',
                $operation,
                is_string($row['edited_by_type'] ?? null) ? (string) $row['edited_by_type'] : self::PARTICIPANT_ADMIN,
                (int) ($row['edited_by_id'] ?? 0),
                is_string($row['edited_by_username'] ?? null) ? (string) $row['edited_by_username'] : null,
                DbDateTime::parse((string) ($row['created_at'] ?? '')),
            );
        }

        return $revisions;
    }

    public function countForJob(int $jobId): int
    {
        return (int) (new Query($this->connection))
            ->from(self::TABLE)
            ->where(['job_id' => $jobId])
            ->count();
    }
}
