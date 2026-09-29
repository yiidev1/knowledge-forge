<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Records WHY a recording was left for a human to confirm the speakers.
 *
 * ## The problem it solves
 *
 * `speaker_separation_status` has only ever said NEEDS_REVIEW, and that one word covers six quite
 * different situations reached at six different points in the pipeline. They want different answers: a
 * recording the diarizer heard as one voice cannot be rescued by better role rules, and a cleanly
 * separated call that simply never reached an address question is not a diarization problem at all.
 *
 * The distinction was computed on every run and then thrown away — written into a log sentence and
 * nowhere else. Nothing could count them, so deciding where to spend effort meant guessing at the
 * proportions. This column is that answer, kept.
 *
 * ## It changes no decision
 *
 * Written after the outcome is already settled, read only to explain it. No status, confidence, role,
 * publication or threshold depends on it, and the enum it stores says so in its own docblock. The
 * classifier behaves exactly as it did the day before this column existed.
 *
 * ## Why nullable, and why nothing is back-filled
 *
 * Three quite different rows legitimately hold NULL, and collapsing them would be the first lie this
 * column told:
 *
 *   - every row written before today, whose reason was computed and discarded;
 *   - every recording that did not need review at all;
 *   - every FAILED or NOT_SUPPORTED row, which already says what happened in one word.
 *
 * Guessing at the first group from stored data would produce a figure that looks measured and is not —
 * the diarizer's own output is not retained, so a recomputation could only re-run the later gates on
 * segments the earlier gates may have rejected. `kf:audio:diagnose-speaker-review` recomputes exactly
 * the part that IS derivable and says so; everything else stays NULL, which is honest.
 *
 * ## VARCHAR rather than an ENUM
 *
 * The set will grow as the pipeline is understood better, and a MySQL ENUM makes each addition a
 * migration and a table rewrite. The allow-list lives in `SeparationReviewReason::tryFrom()`, which is
 * the only thing that writes here; a value it does not recognise reads back as NULL rather than as a
 * label nobody can account for.
 */
final class M260929120000AddJobSeparationReviewReason implements RevertibleMigrationInterface
{
    private const JOBS = 'audio_transcription_jobs';

    public function up(MigrationBuilder $b): void
    {
        // Nullable with no default, so adding it rewrites no existing row and locks no upload.
        $b->execute(
            'ALTER TABLE `' . self::JOBS . '`
                ADD COLUMN `speaker_review_reason` VARCHAR(40) NULL AFTER `speaker_role_confidence`',
        );
    }

    /**
     * Safe to drop, unlike the columns beside it.
     *
     * This holds a diagnosis that is derived from other columns, not a fact somebody supplied — losing
     * it costs the ability to count past failures and nothing else. No transcript, no correction and no
     * role decision lives here, so there is nothing to refuse on behalf of.
     */
    public function down(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE `' . self::JOBS . '` DROP COLUMN `speaker_review_reason`');
    }
}
