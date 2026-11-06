<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Each Case Order call recording's length, measured once and kept.
 *
 * ## Why it is stored
 *
 * The Manage Orders and Practice Orders listings hide an order whose recordings are longer than its
 * amount allows (App\Shared\Order58\RecordingDurationLimit). That has to be decided in the query, so
 * paging and counts stay right, and the length was nowhere to be read from: the page measured it in the
 * browser on every visit, through the provider. Now it is measured once, by
 * `kf:order58:call-recordings:measure-durations` or by the page's own duration request, and read back
 * from here.
 *
 * ## Additive only
 *
 * Five nullable or defaulted columns and one index. No existing value is read, changed or removed, and
 * no recording is measured here — the migration does no network work, and a row stays NULL until the
 * background job or a page visit measures it. NULL means "not known yet", which the rule treats as
 * "offer it", so applying this changes no page.
 *
 *  - `duration_seconds` — the recording's length, exactly as `RecordingMeasurement` derives it from the
 *    file size (16,000 bytes a second after the 44-byte header, so 1/16000 s steps: seven decimals hold
 *    it exactly).
 *  - `duration_measured_at` — when that was measured.
 *  - `duration_attempts`, `duration_next_attempt_at`, `duration_failure` — the background job's retry
 *    state: how many measurements failed, when the next may be tried (also the claim a run takes on a
 *    row before measuring it), and why the last one failed.
 *
 * The index serves the background job's question — unmeasured rows whose retry time has come — so a run
 * reads its batch without scanning the table.
 *
 * ## Reverting
 *
 * `down()` drops the columns and the index. What they hold is re-derivable from the provider at any time,
 * so nothing is lost that cannot be measured again — but turning `RECORDING_DURATION_FILTER_ENABLED`
 * off is the rollback to reach for first: it stops the rule without touching the schema.
 */
final class M261106120000AddCallRecordingDuration implements RevertibleMigrationInterface
{
    private const TABLE = '{{%order58_call_recordings}}';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE ' . self::TABLE . ' '
            . 'ADD COLUMN `duration_seconds` DECIMAL(13,7) NULL AFTER `delisted_at`, '
            . 'ADD COLUMN `duration_measured_at` DATETIME NULL AFTER `duration_seconds`, '
            . 'ADD COLUMN `duration_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `duration_measured_at`, '
            . 'ADD COLUMN `duration_next_attempt_at` DATETIME NULL AFTER `duration_attempts`, '
            . 'ADD COLUMN `duration_failure` VARCHAR(32) NULL AFTER `duration_next_attempt_at`',
        );

        $b->execute(
            'ALTER TABLE ' . self::TABLE . ' '
            . 'ADD INDEX `ix_o58_call_recordings_duration_queue` '
            . '(`duration_seconds`, `duration_next_attempt_at`, `id`)',
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE ' . self::TABLE . ' '
            . 'DROP INDEX `ix_o58_call_recordings_duration_queue`, '
            . 'DROP COLUMN `duration_failure`, '
            . 'DROP COLUMN `duration_next_attempt_at`, '
            . 'DROP COLUMN `duration_attempts`, '
            . 'DROP COLUMN `duration_measured_at`, '
            . 'DROP COLUMN `duration_seconds`',
        );
    }
}
