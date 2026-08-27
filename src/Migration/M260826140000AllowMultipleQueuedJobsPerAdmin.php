<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Let one administrator queue as many recordings as they like.
 *
 * The original schema enforced "one active job per administrator" in the database: a stored generated
 * column that equalled `uploaded_by_admin_id` while a job was QUEUED or PROCESSING and was NULL
 * otherwise, with a unique index on it. That made a second upload a database error rather than a race.
 *
 * It solved the wrong problem. The constraint that actually matters is **one job processing at a
 * time**, and that is the worker's business — it already holds a `flock` and claims rows with an
 * atomic conditional UPDATE. Pushing the limit up into the upload form only stopped an administrator
 * queueing work, which is exactly what a queue is for.
 *
 * So the generated column and its index go, and nothing replaces them: uploads are unbounded (subject
 * to `AUDIO_TRANSCRIPTION_MAX_QUEUE`), while concurrency stays where it belongs.
 *
 * **`uploaded_by_admin_id` is kept**, along with its own index and its foreign key. It is audit
 * metadata — who uploaded what — and always was; it simply stops being an access or rate control.
 *
 * A forward migration rather than an edit to the original: the original has already been applied here,
 * and rewriting applied history is how two machines end up with different schemas and no way to tell.
 *
 * No row is read, written or deleted. Dropping a generated column and an index touches no data.
 */
final class M260826140000AllowMultipleQueuedJobsPerAdmin implements RevertibleMigrationInterface
{
    private const TABLE = 'audio_transcription_jobs';
    private const INDEX = 'ux_audio_transcription_jobs_active_uploader';
    private const COLUMN = 'active_uploader_admin_id';

    public function up(MigrationBuilder $b): void
    {
        // Index first: MySQL refuses to drop a column an index still depends on.
        $b->execute('ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::INDEX . '`');
        $b->execute('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `' . self::COLUMN . '`');
    }

    public function down(MigrationBuilder $b): void
    {
        // Reversible, but only from a state that satisfies the constraint being restored. If any
        // administrator currently has two active jobs — which is now perfectly legal — recreating the
        // unique index would fail on duplicate keys. Refuse with an explanation rather than let MySQL
        // report a bare "Duplicate entry" that gives no clue what to do about it.
        $conflicts = (int) $b->getDb()->createCommand(
            'SELECT COUNT(*) FROM ('
            . '  SELECT `uploaded_by_admin_id` FROM `' . self::TABLE . '`'
            . "  WHERE `status` IN ('QUEUED', 'PROCESSING')"
            . '  GROUP BY `uploaded_by_admin_id` HAVING COUNT(*) > 1'
            . ') AS duplicates',
        )->queryScalar();

        if ($conflicts > 0) {
            throw new \RuntimeException(
                'migrate:down aborted: ' . $conflicts . ' administrator(s) currently have more than one '
                . 'QUEUED or PROCESSING job, which the restored unique index would forbid. Let the queue '
                . 'drain, or delete the surplus jobs deliberately, then run this again. No data was changed.',
            );
        }

        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`'
            . ' ADD COLUMN `' . self::COLUMN . '` BIGINT'
            . '   GENERATED ALWAYS AS ('
            . "     CASE WHEN `status` IN ('QUEUED', 'PROCESSING') THEN `uploaded_by_admin_id` END"
            . '   ) STORED,'
            . ' ADD UNIQUE INDEX `' . self::INDEX . '` (`' . self::COLUMN . '`)',
        );
    }
}
