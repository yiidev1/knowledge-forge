<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * The Audio-to-Text queue, plus the worker's heartbeat row.
 *
 * Raw SQL rather than the fluent builder because two things here cannot be expressed with it: a stored
 * generated column, and a CHECK constraint. Both are load-bearing.
 *
 * **`active_uploader_admin_id`** equals `uploaded_by_admin_id` while a job is QUEUED or PROCESSING and
 * is NULL once it is finished. MySQL exempts NULL from uniqueness, so completed jobs never collide,
 * while a second *active* job for the same administrator is refused by the database itself — however
 * two concurrent uploads happen to interleave. Counting rows and then inserting is not a check under
 * concurrency; this is.
 *
 * **The foreign key is RESTRICT, not CASCADE.** That is forced, not preferred: MySQL refuses a foreign
 * key with CASCADE on the base column of a stored generated column, failing with *"1215 Cannot add
 * foreign key constraint"*. The choice was between the constraint and the race-proof unique index, and
 * the index wins — the application has no code path that deletes an administrator.
 *
 * No audio is stored in the database. `stored_audio_path` holds a bare filename, and only until the
 * worker deletes the recording.
 */
final class M260826120000CreateAudioTranscriptionJobs implements RevertibleMigrationInterface
{
    private const TABLE = 'audio_transcription_jobs';
    private const HEARTBEAT = 'audio_worker_heartbeat';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'CREATE TABLE `' . self::TABLE . '` ('
            . ' `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            // 32 random hex characters. The only identifier the browser ever sees, so a job URL is
            // neither guessable nor enumerable and the internal id never leaves the server.
            . ' `public_id` CHAR(32) NOT NULL,'
            // Audit metadata and the basis of the per-administrator limit. NOT an access-control key:
            // every authorized administrator may view every job.
            . ' `uploaded_by_admin_id` BIGINT NOT NULL,'
            . ' `status` VARCHAR(16) NOT NULL,'
            // Additive telemetry. Nullable so a row written by an older worker stays perfectly valid.
            . ' `processing_stage` VARCHAR(24) NULL,'
            . ' `original_filename` VARCHAR(255) NOT NULL,'
            . ' `stored_audio_path` VARCHAR(190) NULL,'
            . ' `duration_seconds` DECIMAL(9,2) NULL,'
            . ' `transcript` TEXT NULL,'
            . ' `detected_language` VARCHAR(16) NULL,'
            // User-facing wording only. Exit codes, stderr and filesystem paths belong in the log.
            . ' `error_message` VARCHAR(255) NULL,'
            . ' `created_at` DATETIME NOT NULL,'
            . ' `started_at` DATETIME NULL,'
            . ' `completed_at` DATETIME NULL,'
            . ' `expires_at` DATETIME NOT NULL,'
            . ' PRIMARY KEY (`id`),'
            . ' UNIQUE KEY `ux_audio_transcription_jobs_public_id` (`public_id`),'
            // Makes the worker's "oldest QUEUED" an index scan rather than a sort.
            . ' KEY `ix_audio_transcription_jobs_status` (`status`, `id`),'
            . ' KEY `ix_audio_transcription_jobs_expires_at` (`expires_at`),'
            . ' KEY `ix_audio_transcription_jobs_uploader` (`uploaded_by_admin_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`'
            . ' ADD COLUMN `active_uploader_admin_id` BIGINT'
            . '   GENERATED ALWAYS AS ('
            . "     CASE WHEN `status` IN ('QUEUED', 'PROCESSING') THEN `uploaded_by_admin_id` END"
            . '   ) STORED,'
            . ' ADD UNIQUE INDEX `ux_audio_transcription_jobs_active_uploader` (`active_uploader_admin_id`)',
        );

        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`'
            . ' ADD CONSTRAINT `fk_audio_transcription_jobs_admin`'
            . '   FOREIGN KEY (`uploaded_by_admin_id`) REFERENCES `admin_users` (`id`)'
            . '   ON DELETE RESTRICT ON UPDATE RESTRICT',
        );

        // One row, forever. The CHECK is what makes "single row" a property of the schema rather than a
        // convention the repository is trusted to honour.
        $b->execute(
            'CREATE TABLE `' . self::HEARTBEAT . '` ('
            . ' `id` TINYINT UNSIGNED NOT NULL,'
            . ' `started_at` DATETIME NOT NULL,'
            // Process liveness: is a worker alive this instant.
            . ' `beat_at` DATETIME NOT NULL,'
            . ' `state` VARCHAR(10) NOT NULL,'
            // Scheduling: is something still invoking the worker. Stamped by every invocation, including
            // a --once tick that finds nothing to do — the case that proves a timer is alive.
            . ' `last_tick_at` DATETIME NOT NULL,'
            . ' `mode` VARCHAR(10) NOT NULL,'
            . ' `last_tick_outcome` VARCHAR(10) NOT NULL DEFAULT \'\','
            // Diagnostics only; never rendered.
            . ' `current_job_id` BIGINT UNSIGNED NULL,'
            . ' PRIMARY KEY (`id`),'
            . ' CONSTRAINT `chk_audio_worker_heartbeat_single` CHECK (`id` = 1)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
    }

    public function down(MigrationBuilder $b): void
    {
        // Transcripts are first-party content that cannot be reconstructed from anything else — the
        // recordings were deleted as soon as they were processed. Refuse rather than destroy them
        // silently, matching the house rule established by the chat-score migrations.
        $rows = (int) $b->getDb()
            ->createCommand('SELECT COUNT(*) FROM `' . self::TABLE . '`')
            ->queryScalar();

        if ($rows > 0) {
            throw new RuntimeException(
                'migrate:down aborted: `' . self::TABLE . '` still holds ' . $rows . ' job(s), including '
                . 'transcripts that cannot be regenerated because the source audio has been deleted. '
                . 'Export or delete them deliberately first.',
            );
        }

        $b->execute('DROP TABLE IF EXISTS `' . self::HEARTBEAT . '`');
        // Dropping the table takes the foreign key, the generated column and every index with it.
        $b->execute('DROP TABLE IF EXISTS `' . self::TABLE . '`');
    }
}
