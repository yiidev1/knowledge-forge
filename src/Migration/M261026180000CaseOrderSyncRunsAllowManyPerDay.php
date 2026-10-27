<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Several Case Order synchronisations a day, with at most one running at a time.
 *
 * ## What changes, and why the old rule has to go
 *
 * `UNIQUE (business_day)` made the day itself the lock. That rationed the provider's allowance, but it
 * also made the day the identity of a run — so a second synchronisation could never be recorded, even
 * when the provider permitted one and the orders had genuinely moved on. Morning returns four orders,
 * afternoon returns ten; the afternoon six were unreachable.
 *
 * The run id is now the identity. Any number of FINISHED runs may share a date.
 *
 * ## One active run, enforced by the database rather than by a check
 *
 * `active_guard` is `1` exactly while a run is unfinished and `NULL` otherwise, and it carries a
 * UNIQUE index. NULLs are distinct in a MySQL unique index, so finished runs never collide, while a
 * second claim against a live run is refused by the index itself. There is no read-before-write and
 * therefore no window: two workers racing both INSERT, and one loses.
 *
 * The same generated-column idiom `practice_topics.live_name_key` already uses here.
 *
 * ## A lease, so an abandoned run cannot block forever
 *
 * `lease_expires_at` is when a crashed holder stops being believed. **Expiry is not proof that the
 * provider was never called** — a process can die after the request went out — so recovery marks the
 * run failed at a stage that still counts the allowance as spent. Nothing here retries automatically.
 *
 * ## Nothing is deleted
 *
 * Existing runs keep their ids and every column. Run 67 is untouched.
 */
final class M261026180000CaseOrderSyncRunsAllowManyPerDay implements RevertibleMigrationInterface
{
    private const TABLE = 'order58_case_order_sync_runs';

    public function up(MigrationBuilder $b): void
    {
        // The day stops being unique. It stays indexed, because "today's runs" is the commonest read.
        $b->execute('ALTER TABLE `' . self::TABLE . '` DROP INDEX `ux_case_order_sync_runs_day`');

        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`
                ADD COLUMN `trigger_source` VARCHAR(32) NOT NULL DEFAULT \'manual\'
                    COMMENT \'manual | load-calls | scheduler\',
                ADD COLUMN `orders_unchanged` INT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN `lease_expires_at` DATETIME NULL,
                ADD COLUMN `active_guard` TINYINT UNSIGNED
                    GENERATED ALWAYS AS (
                        IF(`status` IN (\'CLAIMED\',\'FETCHED\',\'PROCESSING\'), 1, NULL)
                    ) STORED',
        );

        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`
                ADD UNIQUE KEY `ux_case_order_sync_runs_active` (`active_guard`),
                ADD KEY `ix_case_order_sync_runs_day` (`business_day`, `started_at`),
                ADD KEY `ix_case_order_sync_runs_settled` (`status`, `completed_at`)',
        );
    }

    public function down(MigrationBuilder $b): void
    {
        /*
         * Reversible only while one run per day holds.
         *
         * Restoring `UNIQUE (business_day)` on a table that has since recorded two runs for one date
         * would fail halfway through the ALTER. Refusing with an explanation is better than a
         * half-applied rollback.
         */
        $clash = (int) $b->getDb()->createCommand(
            'SELECT COUNT(*) FROM (SELECT `business_day` FROM `' . self::TABLE . '`
               GROUP BY `business_day` HAVING COUNT(*) > 1) AS d',
        )->queryScalar();

        if ($clash > 0) {
            throw new \RuntimeException(
                'Cannot restore UNIQUE(business_day): ' . $clash . ' day(s) already hold more than one '
                . 'run. Reverting would have to delete synchronisation history, which this migration '
                . 'will not do.',
            );
        }

        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`
                DROP INDEX `ux_case_order_sync_runs_active`,
                DROP INDEX `ix_case_order_sync_runs_day`,
                DROP INDEX `ix_case_order_sync_runs_settled`,
                DROP COLUMN `active_guard`,
                DROP COLUMN `lease_expires_at`,
                DROP COLUMN `orders_unchanged`,
                DROP COLUMN `trigger_source`',
        );

        $b->execute(
            'ALTER TABLE `' . self::TABLE . '` ADD UNIQUE KEY `ux_case_order_sync_runs_day` (`business_day`)',
        );
    }
}
