<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Customer and agent text, alongside the audit trail for how they were derived.
 *
 * A second migration rather than extra columns on the first, so the speaker-separation enhancement can
 * be rolled back on its own without disturbing a working transcription queue. Reverting this leaves
 * every job, transcript and detected language exactly where it was.
 *
 * Every column is nullable and nothing is backfilled, so existing jobs stay readable and no row is
 * rewritten. `transcript` is deliberately untouched: the complete transcript keeps its existing
 * meaning, its existing download route and its existing behaviour, and the split is stored beside it
 * rather than replacing it.
 *
 * `speaker_segments` holds the timestamped, neutral-labelled utterances behind the two text columns.
 * Keeping it means a mapping can be audited later — without it, an agent/customer split is an
 * assertion nobody can check.
 */
final class M260826120100AddSpeakerSeparationColumns implements RevertibleMigrationInterface
{
    private const TABLE = 'audio_transcription_jobs';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`'
            . ' ADD COLUMN `agent_text` TEXT NULL AFTER `detected_language`,'
            . ' ADD COLUMN `customer_text` TEXT NULL AFTER `agent_text`,'
            // JSON rather than TEXT: MySQL validates it on write, so a malformed encode fails at the
            // point of the bug instead of leaving a column nobody can parse months later.
            . ' ADD COLUMN `speaker_segments` JSON NULL AFTER `customer_text`,'
            . ' ADD COLUMN `speaker_separation_status` VARCHAR(16) NULL AFTER `speaker_segments`,'
            . ' ADD COLUMN `speaker_separation_method` VARCHAR(32) NULL AFTER `speaker_separation_status`,'
            // 0.000-1.000. The normalised margin between the two possible role assignments; below the
            // configured threshold the result is held back for review rather than published.
            . ' ADD COLUMN `speaker_role_confidence` DECIMAL(4,3) NULL AFTER `speaker_separation_method`,'
            . ' ADD COLUMN `speaker_separation_completed_at` DATETIME NULL AFTER `speaker_role_confidence`',
        );
    }

    public function down(MigrationBuilder $b): void
    {
        // Safe to drop unconditionally, unlike the table itself: everything here is derived, and
        // `transcript` — the irreplaceable part, since the source audio is deleted after processing —
        // is untouched and stays in place.
        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`'
            . ' DROP COLUMN `speaker_separation_completed_at`,'
            . ' DROP COLUMN `speaker_role_confidence`,'
            . ' DROP COLUMN `speaker_separation_method`,'
            . ' DROP COLUMN `speaker_separation_status`,'
            . ' DROP COLUMN `speaker_segments`,'
            . ' DROP COLUMN `customer_text`,'
            . ' DROP COLUMN `agent_text`',
        );
    }
}
