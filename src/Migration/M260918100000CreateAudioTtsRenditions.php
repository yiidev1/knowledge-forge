<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Stores the AI training audio generated from a transcript, and the per-upload request for it.
 *
 * ## Why a rendition belongs to a job rather than to a conversation
 *
 * Every output maps to exactly one recording: a mixed upload produces one MIXED file from its single
 * job, and a Customer + Agent pair produces one file per child from that child's own transcript. Keying
 * on the job therefore needs no join to answer "is this one current?", inherits the job's `ON DELETE
 * CASCADE`, and lines up with the generated file, which lives under that job's public id.
 *
 * The unique key admits all three output types even though this phase writes one per job. That is
 * deliberate: offering per-role audio from a mixed recording later is a change to one PHP method, not a
 * migration.
 *
 * ## Why two hashes and a render key
 *
 * `review_count` counts *operations*, not content — a REVERT increments it while restoring identical
 * text, and CONFIRM_ROLES increments it while changing no wording at all. Pinning audio to it would
 * raise "your transcript changed" on calls where nothing was said differently, and clearing that costs
 * money. So staleness is decided by a digest of the text itself.
 *
 * `requested_hash` is the transcript the current attempt targets; `file_hash` is the transcript the file
 * on disk was actually made from. Keeping both is what lets a failed regeneration leave the previous,
 * still-valid audio playable — the row can say "the latest attempt was for X and failed" without
 * disowning the good file it already has.
 *
 * `file_render_key` records the voice, format and chunk size the file was made with. A change there
 * means the audio would come out different while the words are identical, which is a different sentence
 * to show an administrator than "the transcript changed", and must not be conflated with it.
 *
 * ## Why `attempt_token`
 *
 * `UNIQUE (job_id, output_type)` forces the enqueue to be an upsert, and an upsert is exactly how a row
 * gets flipped from GENERATING back to QUEUED underneath a worker that is mid-flight — after which a
 * second worker claims it and a second paid generation runs, both racing to write `file_name`. The
 * token is rotated on every enqueue and carried by the worker, so a terminal write is a compare-and-swap
 * and a slow first worker can never overwrite a newer result. It is the same idea `review_count` already
 * applies to speaker corrections.
 *
 * ## Why `generating_since` is its own column
 *
 * A row abandoned mid-generation — a deploy, an OOM, a systemd timeout — is unreachable afterwards,
 * because the claim looks for QUEUED. `updated_at` cannot answer "how long has this been running", since
 * every write moves it. A dedicated timestamp gives the TTS worker's stale sweep something unambiguous
 * to measure, following `findStale()` on the transcription side.
 *
 * ## No credential is ever stored here
 *
 * `provider` and the two model columns record *what was used*, the way `transcription_provider` and
 * `speaker_separation_method` already do. Nothing in this table is a secret, and nothing in it may
 * become one.
 *
 * ## Raw SQL
 *
 * CHECK constraints, which the fluent builder cannot express — the same reason
 * {@see M260831140000AddReviewedConversation} gives.
 */
final class M260918100000CreateAudioTtsRenditions implements RevertibleMigrationInterface
{
    private const RENDITIONS = 'audio_tts_renditions';
    private const CONVERSATIONS = 'audio_conversations';
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'CREATE TABLE `' . self::RENDITIONS . '` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `job_id` BIGINT UNSIGNED NOT NULL,
                `output_type` VARCHAR(16) NOT NULL,
                `status` VARCHAR(16) NOT NULL,
                -- Rotated on every enqueue. The worker carries it and every terminal write compares it,
                -- so a superseded attempt cannot publish over a newer one.
                `attempt_token` CHAR(32) NOT NULL,
                -- The transcript this attempt targets.
                `requested_hash` CHAR(64) NOT NULL,
                -- The last file that was generated SUCCESSFULLY, and what it was made from. Both survive
                -- a failed retry untouched, which is what keeps the previous audio playable.
                `file_name` VARCHAR(64) NULL,
                `file_hash` CHAR(64) NULL,
                `file_render_key` VARCHAR(160) NULL,
                `file_bytes` BIGINT UNSIGNED NULL,
                -- Billed characters and requests, kept for cost audit. Never used to make a decision.
                `character_count` INT UNSIGNED NULL,
                `request_count` INT UNSIGNED NULL,
                `provider` VARCHAR(16) NOT NULL,
                `model_customer` VARCHAR(64) NULL,
                `model_agent` VARCHAR(64) NULL,
                `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                -- Written for an administrator to read, and redacted before it is stored: this column is
                -- rendered on a page, so it is held to the same rule as the log.
                `error_message` VARCHAR(255) NULL,
                -- NULL means the queue asked for it because the upload did. A value names the
                -- administrator who pressed the button, which is the paid action worth attributing.
                `requested_by_admin_id` BIGINT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                `generating_since` DATETIME NULL,
                `generated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_audio_tts_renditions_job_type` (`job_id`, `output_type`),
                KEY `ix_audio_tts_renditions_queue` (`status`, `id`),
                CONSTRAINT `fk_audio_tts_renditions_job`
                    FOREIGN KEY (`job_id`) REFERENCES `audio_transcription_jobs` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                -- RESTRICT so removing an administrator cannot quietly orphan the attribution, matching
                -- every other admin reference in this feature.
                CONSTRAINT `fk_audio_tts_renditions_admin`
                    FOREIGN KEY (`requested_by_admin_id`) REFERENCES `admin_users` (`id`)
                    ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT `chk_audio_tts_renditions_type`
                    CHECK (`output_type` IN (\'MIXED\', \'CUSTOMER\', \'AGENT\')),
                CONSTRAINT `chk_audio_tts_renditions_status`
                    CHECK (`status` IN (\'QUEUED\', \'GENERATING\', \'READY\', \'FAILED\'))
            ) ' . self::TABLE_OPTIONS,
        );

        // Not nullable, because "the administrator did not ask for this" is a real answer and 0 says it
        // exactly. A default of 0 means adding the column rewrites no existing row's meaning: every
        // upload made before this feature existed asked for nothing, which is true.
        $b->execute(
            'ALTER TABLE `' . self::CONVERSATIONS . '`
                ADD COLUMN `generate_ai_audio` TINYINT(1) NOT NULL DEFAULT 0 AFTER `mode`',
        );
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * A generated file is paid for. Dropping the table would leave the audio on disk with nothing
     * pointing at it — not recoverable through the application, not swept by anything that knows what it
     * is, and silently re-billable on the next request. So this stops and says how much is at stake.
     *
     * A database that has never generated any reverts cleanly: there is nothing to lose.
     */
    public function down(MigrationBuilder $b): void
    {
        $generated = (int) $b->getDb()->createCommand(
            'SELECT COUNT(*) FROM `' . self::RENDITIONS . '` WHERE `file_name` IS NOT NULL',
        )->queryScalar();

        if ($generated > 0) {
            throw new RuntimeException(
                'migrate:down aborted: ' . $generated . ' generated audio file(s) are recorded here, and '
                . 'this table is the only thing that knows they exist. Dropping it would strand paid-for '
                . 'audio on disk. Remove those renditions first, or export the table.',
            );
        }

        $b->execute('ALTER TABLE `' . self::CONVERSATIONS . '` DROP COLUMN `generate_ai_audio`');
        $b->execute('DROP TABLE IF EXISTS `' . self::RENDITIONS . '`');
    }
}
