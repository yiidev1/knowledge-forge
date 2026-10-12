<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Per-operation progress for original-order-item synchronisation.
 *
 * ## Why a junction table and not a column on the order
 *
 * The obvious design — `order58_orders.items_batch_id` — holds exactly one batch per order, so the
 * second operation to touch an order silently erases the first one's record of it. That happens
 * routinely: an administrator presses "Sync orders" for a date range while the recording importer is
 * syncing the same day's orders from a settled batch. Both are legitimate, both are waiting on the same
 * orders, and both need to finish independently.
 *
 * So the relationship is its own table. An order can be enrolled in any number of open batches, each
 * keeping its own settlement.
 *
 * ## One API request, many batches settled
 *
 * The fetch is keyed on the ORDER, never on the batch, and the existing atomic claim on
 * `order58_orders.items_lease_owner` remains the only serialisation point — one provider request per
 * order no matter how many batches reference it. When that request reaches a terminal outcome, every
 * open enrolment row for the order is settled with the same result. Nothing re-fetches on another
 * batch's behalf, and no batch reports completion for work it did not wait for.
 *
 * ## Completion is derived, never asserted
 *
 * A batch is finished when it has no unsettled rows. There is deliberately no counter to increment:
 * a counter and a set of rows are two sources of truth, and the counter is the one that drifts when a
 * process is killed between the write and the increment.
 *
 * ## `eligible_count` is a scope record, not a progress figure
 *
 * It records how many orders the sync intended to enrol, which is what makes an interrupted Orders Sync
 * visible: if the sync stopped early, `eligible_count` and the number of enrolment rows disagree, and
 * `orders_truncated` says so outright rather than letting a partial scope read as a complete one.
 */
final class M261009160000CreateItemSyncBatches implements RevertibleMigrationInterface
{
    private const BATCHES = '{{%order58_item_sync_batches}}';
    private const BATCH_ORDERS = '{{%order58_item_sync_batch_orders}}';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::BATCHES . ' (
                `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_account_id`      BIGINT UNSIGNED NOT NULL,
                `date_from`             DATE NULL,
                `date_to`               DATE NULL,
                -- Which user action opened this: `orders_sync` or `recording_sync`. Shown on the page,
                -- so an operator can tell their own click from the importer working behind them.
                `trigger_source`        VARCHAR(16) NOT NULL,
                `requested_by_admin_id` BIGINT NULL,
                `orders_received`       INT UNSIGNED NOT NULL DEFAULT 0,
                `orders_saved`          INT UNSIGNED NOT NULL DEFAULT 0,
                -- How many orders the sync INTENDED to enrol. Compared against the enrolment rows to
                -- detect a scope that was cut short; never used as a progress denominator.
                `eligible_count`        INT UNSIGNED NOT NULL DEFAULT 0,
                -- The Orders Sync ran out of time before covering its whole range.
                `orders_truncated`      TINYINT(1) NOT NULL DEFAULT 0,
                `orders_error`          VARCHAR(255) NULL,
                `started_at`            DATETIME NOT NULL,
                `finished_at`           DATETIME NULL,
                -- RUNNING | COMPLETED | PARTIAL | FAILED. Recomputed from the enrolment rows; never
                -- advanced optimistically.
                `outcome`               VARCHAR(16) NOT NULL DEFAULT \'RUNNING\',
                `created_at`            DATETIME NOT NULL,
                `updated_at`            DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `ix_item_batches_open` (`outcome`, `started_at`),
                KEY `ix_item_batches_store` (`store_account_id`, `started_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        $b->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::BATCH_ORDERS . ' (
                `batch_id`         BIGINT UNSIGNED NOT NULL,
                `store_account_id` BIGINT UNSIGNED NOT NULL,
                `source_order_id`  BIGINT UNSIGNED NOT NULL,
                -- The order\'s items_status at the moment this batch enrolled it. Kept for history:
                -- it is how "this batch found it already synced" stays true after a later refresh.
                `enrolled_state`   VARCHAR(16) NOT NULL,
                -- 0 when this batch never had anything to wait for, so it cannot block completion.
                `required`         TINYINT(1) NOT NULL DEFAULT 1,
                `settled_at`       DATETIME NULL,
                -- SYNCED | EMPTY | UNCHANGED | SKIPPED | FAILED
                `outcome`          VARCHAR(16) NULL,
                `error`            VARCHAR(255) NULL,
                `created_at`       DATETIME NOT NULL,
                `updated_at`       DATETIME NOT NULL,
                PRIMARY KEY (`batch_id`, `store_account_id`, `source_order_id`),
                -- The settlement pass asks "which open rows reference this order?" after every fetch,
                -- so that lookup is the one that must stay cheap.
                KEY `ix_item_batch_orders_order` (`store_account_id`, `source_order_id`, `settled_at`),
                CONSTRAINT `fk_item_batch_orders_batch` FOREIGN KEY (`batch_id`)
                    REFERENCES {{%order58_item_sync_batches}} (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
    }

    /**
     * Drops both tables, refusing while any batch is still running.
     *
     * A running batch is an operation somebody is watching. Removing its record mid-flight would leave
     * the page polling for a batch that no longer exists, so this asks for a deliberate decision rather
     * than making one.
     */
    public function down(MigrationBuilder $b): void
    {
        $running = (int) $b->getDb()
            ->createCommand('SELECT COUNT(*) FROM ' . self::BATCHES . ' WHERE outcome = \'RUNNING\'')
            ->queryScalar();

        if ($running > 0) {
            throw new RuntimeException(sprintf(
                '%d item sync batch(es) are still RUNNING; a page may be watching them. '
                . 'Let them finish, or settle them deliberately, before reverting.',
                $running,
            ));
        }

        // The child first: its foreign key is what would otherwise refuse the parent.
        $b->execute('DROP TABLE IF EXISTS ' . self::BATCH_ORDERS);
        $b->execute('DROP TABLE IF EXISTS ' . self::BATCHES);
    }
}
