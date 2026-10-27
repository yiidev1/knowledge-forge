<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * The Case Order sync's run history, and the lock that rations it.
 *
 * ## One table doing two jobs, on purpose
 *
 * The provider allows **one request per day for the whole application** — not per store, per user or
 * per button. So the allowance has to be claimed somewhere that two browser tabs, two operators and
 * a worker cannot all claim at once.
 *
 * A row here IS the claim. `ux_case_order_sync_runs_day` makes the business day unique, so the
 * claim is an `INSERT` that either succeeds or collides: whoever inserts the row owns today's
 * request, and everybody else is told today is already spent. No application-level check-then-act,
 * no advisory lock to leak, and it works across processes because the database arbitrates.
 *
 * The same row then becomes the history: what was fetched, what imported, what failed. One record
 * rather than a lock table plus an audit table, because they are the same fact at two moments.
 *
 * ## Why the day is a stored string
 *
 * `business_day` is the date in the configured timezone, written as `YYYY-MM-DD`. Not derived from
 * `created_at` at read time: the client's day is not UTC's, and a sync at 23:30 local must not
 * become eligible again forty minutes later because a different clock rolled over. Storing the day
 * the claim was made for means the rule cannot drift when the timezone setting changes.
 *
 * ## Why the response is staged
 *
 * `staged_payload` keeps the sanitized response that was actually received. Local processing can
 * then be retried — a database error, a bad deploy, a half-finished import — **without spending
 * another request**. It holds the REDACTED document; nothing with a card number in it is ever
 * written here.
 */
final class M261026120000CreateCaseOrderSyncRuns implements RevertibleMigrationInterface
{
    private const TABLE = '{{%order58_case_order_sync_runs}}';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            <<<SQL
            CREATE TABLE `order58_case_order_sync_runs` (
                `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                -- The claim. One row per business day, which is what rations the allowance.
                `business_day`    DATE        NOT NULL,
                -- The zone that day was computed in, so a later settings change is auditable rather
                -- than silently reinterpreting history.
                `timezone`        VARCHAR(64) NOT NULL,

                -- CLAIMED -> FETCHED -> PROCESSING -> COMPLETED | FAILED
                `status`          VARCHAR(16) NOT NULL,
                -- Which stage a failure happened at, so an operator knows whether the day's request
                -- was actually spent or whether it failed before reaching the provider.
                `stage`           VARCHAR(32) NULL,

                `started_at`      DATETIME    NOT NULL,
                `fetched_at`      DATETIME    NULL,
                `completed_at`    DATETIME    NULL,

                -- What the provider answered. Status only; never a token, never a card.
                `http_status`     SMALLINT UNSIGNED NULL,
                `response_bytes`  BIGINT UNSIGNED   NULL,

                `orders_received` INT UNSIGNED NOT NULL DEFAULT 0,
                `orders_inserted` INT UNSIGNED NOT NULL DEFAULT 0,
                `orders_updated`  INT UNSIGNED NOT NULL DEFAULT 0,
                `orders_skipped`  INT UNSIGNED NOT NULL DEFAULT 0,
                `items_imported`  INT UNSIGNED NOT NULL DEFAULT 0,
                `failures`        INT UNSIGNED NOT NULL DEFAULT 0,

                -- One short, sanitized sentence for the operator. Diagnostics, never secrets.
                `error_summary`   TEXT NULL,

                -- The sanitized response, so local processing can be retried without another call.
                `staged_payload`  LONGTEXT NULL,

                `created_at`      DATETIME NOT NULL,
                `updated_at`      DATETIME NOT NULL,

                PRIMARY KEY (`id`),

                -- THE allowance. An INSERT that collides here is the whole rate limit.
                UNIQUE KEY `ux_case_order_sync_runs_day` (`business_day`),

                -- "What happened last?" on the sync screen, without scanning the table.
                KEY `ix_case_order_sync_runs_recent` (`status`, `started_at`),

                CONSTRAINT `chk_case_order_sync_runs_status`
                    CHECK (`status` IN ('CLAIMED','FETCHED','PROCESSING','COMPLETED','FAILED'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL,
        );
    }

    /**
     * Refuses rather than destroys, as this project's other migrations do.
     *
     * Dropping this table would not only lose the history — it would release every day's claim, so
     * a redeploy could spend an allowance the client has already been billed for.
     */
    public function down(MigrationBuilder $b): void
    {
        $rows = (int) $b->getDb()
            ->createCommand('SELECT COUNT(*) FROM ' . self::TABLE)
            ->queryScalar();

        if ($rows > 0) {
            throw new \RuntimeException(
                'order58_case_order_sync_runs holds ' . $rows . ' run(s). Dropping it would erase the '
                . 'record of which days have already been spent against the provider\'s one-call-a-day '
                . 'limit. Remove the rows deliberately first if that is really intended.',
            );
        }

        $b->execute('DROP TABLE ' . self::TABLE);
    }
}
