<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Makes the speech-to-text engine selectable: a global default, and a per-job record of what was used.
 *
 * ## Why the job needs its own column
 *
 * The global default answers "what will the next upload use?". It cannot answer "what did *this* job
 * use?", because an administrator may change it while the job is still queued, and a per-upload
 * override may have disagreed with it in the first place. Reading the provider back off the setting
 * would therefore rewrite history — a transcript produced by Whisper would start claiming Deepgram the
 * moment the default changed.
 *
 * So the provider is **copied onto the job at enqueue** and never consulted again. This follows
 * `speaker_separation_method`, which records which implementation did the work for exactly the same
 * reason.
 *
 * ## Why the column is nullable and nothing is back-filled
 *
 * Every existing job was transcribed by Whisper, so a back-fill would write a value that is already
 * implied — rows rewritten to say what NULL already says. `NULL` means Whisper, resolved in exactly one
 * method on the job entity, which keeps the legacy rule out of every query and every template.
 *
 * The nullable column with a `NULL OR IN (...)` CHECK copies `source_role` from
 * {@see M260902100000CreateAudioConversations}; the single-row settings table with `CHECK (id = 1)`
 * copies `audio_worker_heartbeat` from {@see M260826120000CreateAudioTranscriptionJobs}.
 *
 * ## Raw SQL
 *
 * CHECK constraints, which the fluent builder cannot express — the same reason
 * `M260831140000AddReviewedConversation` gives.
 */
final class M260915100000AddTranscriptionProvider implements RevertibleMigrationInterface
{
    private const SETTINGS = 'audio_to_text_settings';
    private const JOBS = 'audio_transcription_jobs';
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    /** The engine every existing recording was transcribed with, and the default for every new one. */
    private const DEFAULT_PROVIDER = 'WHISPER';

    public function up(MigrationBuilder $b): void
    {
        // One row, forever — `CHECK (id = 1)` makes a second one a database error rather than a bug
        // that surfaces as two disagreeing defaults. The reader upserts id 1 and never inserts.
        $b->execute(
            'CREATE TABLE `' . self::SETTINGS . '` (
                `id` TINYINT UNSIGNED NOT NULL,
                `default_transcription_provider` VARCHAR(16) NOT NULL DEFAULT \'' . self::DEFAULT_PROVIDER . '\',
                `updated_at` DATETIME NOT NULL,
                -- Nullable: the seeded row predates any administrator, and the very first save is what
                -- gives it an author. RESTRICT so removing an administrator cannot orphan the audit.
                `updated_by_admin_id` BIGINT NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `fk_audio_to_text_settings_admin`
                    FOREIGN KEY (`updated_by_admin_id`) REFERENCES `admin_users` (`id`)
                    ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT `chk_audio_to_text_settings_single`
                    CHECK (`id` = 1),
                CONSTRAINT `chk_audio_to_text_settings_provider`
                    CHECK (`default_transcription_provider` IN (\'WHISPER\', \'DEEPGRAM\'))
            ) ' . self::TABLE_OPTIONS,
        );

        // Seed it here rather than lazily on first read: a row that always exists means the reader is
        // a plain SELECT with no "what if it is missing" branch, and the upsert has something to update.
        $b->execute(
            'INSERT INTO `' . self::SETTINGS . '`
                (`id`, `default_transcription_provider`, `updated_at`, `updated_by_admin_id`)
             VALUES (1, :provider, UTC_TIMESTAMP(), NULL)',
            [':provider' => self::DEFAULT_PROVIDER],
        );

        // Nullable, so adding it rewrites no existing row and no job is locked for the rewrite.
        $b->execute(
            'ALTER TABLE `' . self::JOBS . '`
                ADD COLUMN `transcription_provider` VARCHAR(16) NULL AFTER `source_role`,
                ADD CONSTRAINT `chk_audio_transcription_jobs_transcription_provider`
                    CHECK (`transcription_provider` IS NULL
                           OR `transcription_provider` IN (\'WHISPER\', \'DEEPGRAM\'))',
        );
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * A Deepgram job's provider is recorded nowhere else. Dropping the column would leave its transcript
     * silently reading as Whisper's work — a quiet falsification of the record rather than a visible
     * loss — so this stops instead and says how many rows are at stake.
     *
     * A database that has only ever run Whisper reverts cleanly: there is nothing to lose.
     */
    public function down(MigrationBuilder $b): void
    {
        $deepgram = (int) $b->getDb()->createCommand(
            'SELECT COUNT(*) FROM `' . self::JOBS . '` WHERE `transcription_provider` = \'DEEPGRAM\'',
        )->queryScalar();

        if ($deepgram > 0) {
            throw new RuntimeException(
                'migrate:down aborted: ' . $deepgram . ' job(s) were transcribed by Deepgram, and this '
                . 'column is the only record of that. Dropping it would make those transcripts read as '
                . 'Whisper output. Export the column, or remove those jobs first.',
            );
        }

        $b->execute(
            'ALTER TABLE `' . self::JOBS . '`
                DROP CHECK `chk_audio_transcription_jobs_transcription_provider`',
        );
        $b->execute('ALTER TABLE `' . self::JOBS . '` DROP COLUMN `transcription_provider`');
        $b->execute('DROP TABLE IF EXISTS `' . self::SETTINGS . '`');
    }
}
