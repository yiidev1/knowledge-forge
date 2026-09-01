<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * A reviewed layer over the machine's conversation, plus the audit trail for it.
 *
 * Diarization occasionally draws a turn boundary inside continuous speech, so a clause lands on the
 * wrong speaker, and whisper occasionally mishears a word. Both are per-recording accuracy problems
 * rather than bugs, so an administrator corrects them by hand — attribution *and* wording.
 *
 * ## The rule this schema exists to enforce
 *
 * **The machine's output is never overwritten.** `transcript`, `speaker_segments`, `agent_text` and
 * `customer_text` are written once by the worker and are read-only from then on. A correction is an
 * additional layer:
 *
 *     raw machine result (immutable)  ->  reviewed layer (optional)  ->  what a reader is shown
 *
 * So "Yes. For pikup" stays in `transcript` exactly as it was heard, while the reviewed layer carries
 * "Yes. For pickup". Both remain available: one to show, one to audit the pipeline against. Nothing in
 * this migration, and nothing in the code it enables, writes to a raw column.
 *
 * ## Why the two reviewed text columns exist
 *
 * They are **derived, never authored** — rewritten from `reviewed_segments` in the same transaction, so
 * they cannot drift from it. They exist because the conversions list builds previews in SQL
 * (`LEFT(j.agent_text, :len)`) precisely to avoid pulling whole transcripts for fifteen rows. With
 * these columns that stays one cheap query through `COALESCE`; without them the list would have to
 * decode reviewed JSON per row, against the reason those previews are computed in SQL at all.
 *
 * ## `review_count`
 *
 * Doubles as the optimistic-lock version, exactly as `messages.edit_count` does for chat edits: a save
 * carries the version it read, and the conditional UPDATE refuses if anything moved underneath it. Two
 * administrators correcting one call cannot silently overwrite each other.
 */
final class M260831140000AddReviewedConversation implements RevertibleMigrationInterface
{
    private const JOBS = 'audio_transcription_jobs';
    private const REVISIONS = 'audio_segment_revisions';
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(MigrationBuilder $b): void
    {
        // Raw SQL rather than the fluent builder: this needs an inline foreign key and, below, CHECK
        // constraints — the same reason the chat-score and message-revision migrations use raw SQL.
        //
        // Every column is nullable or defaulted, so the statement cannot fail on existing rows and no
        // backfill is required: a job with `reviewed_segments IS NULL` has simply never been corrected.
        $b->execute(
            'ALTER TABLE `' . self::JOBS . '`'
            // The corrected conversation, as a whole. Carries the reviewed *text* as well as the
            // reviewed attribution, which is why an administrator's wording fix never has to touch
            // `transcript`. Same shape as `speaker_segments`, plus optional per-turn markers the
            // existing decoder ignores (`approx` for a split boundary whose timestamps were inherited,
            // `edited` for a turn whose wording was changed).
            . ' ADD COLUMN `reviewed_segments` JSON NULL AFTER `speaker_segments`,'
            . ' ADD COLUMN `reviewed_agent_text` TEXT NULL AFTER `reviewed_segments`,'
            . ' ADD COLUMN `reviewed_customer_text` TEXT NULL AFTER `reviewed_agent_text`,'
            . ' ADD COLUMN `reviewed_at` DATETIME NULL AFTER `reviewed_customer_text`,'
            . ' ADD COLUMN `reviewed_by_admin_id` BIGINT NULL AFTER `reviewed_at`,'
            // NOT NULL DEFAULT 0: every existing job starts unreviewed at version zero, so the first
            // save can use the same conditional UPDATE as every later one with no special case.
            . ' ADD COLUMN `review_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `reviewed_by_admin_id`,'
            // RESTRICT rather than CASCADE, matching the uploader key: a correction is an audit record
            // of a person's judgement, and deleting an administrator must not quietly erase it.
            . ' ADD CONSTRAINT `fk_audio_transcription_jobs_reviewer`'
            . '   FOREIGN KEY (`reviewed_by_admin_id`) REFERENCES `admin_users` (`id`)'
            . '   ON DELETE RESTRICT ON UPDATE RESTRICT',
        );

        // One row per correction, holding the state that existed *before* it. Storing the prior state
        // rather than the new one matches `message_revisions` and makes the trail walk backwards from
        // the current reviewed conversation to the machine's original — the first revision on any job
        // therefore carries a copy of the raw segments, which is what makes each row self-contained.
        //
        // A text edit needs no special column: the prior snapshot holds the prior wording, so diffing
        // it against the current state shows exactly which turn changed and how.
        $b->execute(
            'CREATE TABLE `' . self::REVISIONS . '` ('
            . ' `id` BIGINT AUTO_INCREMENT PRIMARY KEY,'
            // UNSIGNED to match `audio_transcription_jobs.id`. A signed column here is refused by
            // MySQL with error 3780 — the same mismatch `uploaded_by_admin_id` had to solve, in the
            // other direction, because `admin_users.id` is signed and the jobs key is not.
            . ' `job_id` BIGINT UNSIGNED NOT NULL,'
            . ' `revision_number` INT UNSIGNED NOT NULL,'
            . ' `segments_json` JSON NOT NULL,'
            . ' `operation` VARCHAR(16) NOT NULL,'
            . ' `edited_by_type` VARCHAR(16) NOT NULL,'
            . ' `edited_by_id` BIGINT UNSIGNED NOT NULL,'
            . ' `created_at` DATETIME NOT NULL,'
            // Also the read path: the history of one job, in order, is a single index scan.
            . ' UNIQUE KEY `ux_audio_segment_revisions_job_number` (`job_id`, `revision_number`),'
            // CASCADE here, unlike the reviewer key above: revisions describe a job and are meaningless
            // without it, so purging a recording takes its correction history with it.
            . ' CONSTRAINT `fk_audio_segment_revisions_job` FOREIGN KEY (`job_id`)'
            . '   REFERENCES `' . self::JOBS . '` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,'
            . ' CONSTRAINT `chk_audio_segment_revisions_operation`'
            . "   CHECK (`operation` IN ('MOVE','SPLIT','MERGE','EDIT_TEXT','REVERT')),"
            // 'agent' is permitted so a future agent-facing review surface needs no schema change;
            // nothing writes it today.
            . ' CONSTRAINT `chk_audio_segment_revisions_by_type`'
            . "   CHECK (`edited_by_type` IN ('admin','agent')),"
            . ' CONSTRAINT `chk_audio_segment_revisions_by_id_positive` CHECK (`edited_by_id` > 0),'
            . ' CONSTRAINT `chk_audio_segment_revisions_number_positive` CHECK (`revision_number` > 0)'
            . ') ' . self::TABLE_OPTIONS,
        );
    }

    public function down(MigrationBuilder $b): void
    {
        // Corrections are first-party judgements that exist nowhere else — unlike a projection, they
        // cannot be recomputed. Refuse rather than destroy, matching the chat-score and rule-catalog
        // migrations. The raw machine output is untouched either way, so a job simply returns to
        // showing what the pipeline produced.
        $revisions = (int) $b->getDb()
            ->createCommand('SELECT COUNT(*) FROM `' . self::REVISIONS . '`')
            ->queryScalar();

        if ($revisions > 0) {
            throw new RuntimeException(
                'migrate:down aborted: ' . self::REVISIONS . ' holds ' . $revisions . ' correction(s) '
                . 'that cannot be reconstructed. Export them, or clear the reviewed layer first.',
            );
        }

        $b->execute('DROP TABLE `' . self::REVISIONS . '`');

        // The foreign key must go before the column it is defined on.
        $b->execute(
            'ALTER TABLE `' . self::JOBS . '` DROP FOREIGN KEY `fk_audio_transcription_jobs_reviewer`',
        );
        $b->execute(
            'ALTER TABLE `' . self::JOBS . '`'
            . ' DROP COLUMN `review_count`,'
            . ' DROP COLUMN `reviewed_by_admin_id`,'
            . ' DROP COLUMN `reviewed_at`,'
            . ' DROP COLUMN `reviewed_customer_text`,'
            . ' DROP COLUMN `reviewed_agent_text`,'
            . ' DROP COLUMN `reviewed_segments`',
        );
    }
}
