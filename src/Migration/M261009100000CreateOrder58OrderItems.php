<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Original Order58 order items, and the per-order state of fetching them.
 *
 * ## Why a second API, and a second table
 *
 * The Orders API returns no line items — verified against the stored payloads, which carry 73 top-level
 * keys and no `items` at any depth. Items come from `POST /api/order-item-data/list`, **one order per
 * request**, with its own failure modes. That is why the fetch needs state of its own: "fetched and
 * found none" and "could not be fetched" are different facts, and a JSON blob on the order row cannot
 * express the difference.
 *
 * ## FIVE decisions that look wrong until you know why
 *
 * **1. `account_id` holds `0`, and it is not the store.** The provider's item payload carries
 * `"account_id": "0"` and `"user_id": "0"` — those are the item's own fields and they are preserved
 * verbatim, as every other API field is. The store is `store_account_id`, taken from the REQUEST, which
 * is the same rule `order58_orders` already follows: *"The account is taken from the request, not the
 * record. A response that disagreed with the account we asked about would otherwise file one store's
 * order under another's."* (`OrderMapper::toOrder()`). Scoping, the foreign key and cross-store
 * isolation all hang off `store_account_id`. **Never scope a query by `account_id`.**
 *
 * **2. `desc` is a MySQL reserved word.** It is named `desc` because the API field is `desc` and the
 * decision was to preserve API names. The query builder quotes identifiers, so ordinary reads and
 * writes are fine; any hand-written SQL must backtick it.
 *
 * **3. `items_status` defaults to `PENDING`, not `QUEUED`.** Defaulting to `QUEUED` would make this
 * migration silently enqueue every historical order — 294 at the time of writing — and the first worker
 * tick would start spending real API calls nobody asked for. `PENDING` means "never considered". Orders
 * become `QUEUED` only through the sync path, and only when they are in the range an operator actually
 * asked for.
 *
 * **4. `items_*` are deliberately absent from `DbOrder58OrderRepository::upsert()`.** That method
 * enumerates its `$insert` array, and `ON DUPLICATE KEY UPDATE` only writes the columns listed, so an
 * order re-sync leaves item state alone. Adding these columns to that array would reset every order's
 * item state on every Orders Sync. There is a test.
 *
 * **5. `items_synced_at` is never cleared by a failure.** It is the only thing distinguishing "never
 * successfully synced" (`NULL`) from "a good snapshot is still here, the latest refresh failed"
 * (`NOT NULL`, with `items_status = 'FAILED'`). A failure path that reset it would collapse those two
 * into one and the page would have to guess which it was looking at.
 *
 * ## Money and identity
 *
 * `DECIMAL`, never float: the provider sends `"11.35000"` and five decimals have to survive a round
 * trip. `source_item_id` is the provider's item id and is in the unique key for idempotency only —
 * never for semantic comparison, because demo and original orders carry different item ids for the
 * same dish.
 */
final class M261009100000CreateOrder58OrderItems implements RevertibleMigrationInterface
{
    private const ITEMS = 'order58_order_items';
    private const ORDERS = 'order58_orders';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'CREATE TABLE `' . self::ITEMS . '` (
                `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                -- Verified identity, taken from the REQUEST. See decision 1.
                `store_account_id` BIGINT UNSIGNED NOT NULL,
                `source_order_id`  BIGINT UNSIGNED NOT NULL,
                `source_item_id`   BIGINT UNSIGNED NOT NULL,

                -- The provider\'s own fields, names and values preserved.
                `name`             TEXT NOT NULL,
                `qty`              DECIMAL(12,3) NOT NULL DEFAULT 0,
                `desc`             TEXT NULL,
                `price`            DECIMAL(15,5) NULL,
                `cost_price`       DECIMAL(15,5) NULL,
                `type`             VARCHAR(100) NULL,
                `account_id`       BIGINT UNSIGNED NULL,
                `user_id`          BIGINT UNSIGNED NULL,
                `order_id`         BIGINT UNSIGNED NULL,
                `data`             LONGTEXT NULL,
                `customer_id`      BIGINT UNSIGNED NULL,
                `product_id`       BIGINT UNSIGNED NULL,
                `tag`              TEXT NULL,
                `pack_idx`         INT NULL,
                `flag`             VARCHAR(50) NULL,
                `print_idx`        INT NULL,
                `deleted`          TINYINT NULL,
                `free_qty`         DECIMAL(12,3) NULL,

                -- Derived. The display name keeps its "[F7]" prefix; stripping it, and pulling the
                -- product name and sn out of `data`, happens only inside comparison_json.
                `position`         INT UNSIGNED NOT NULL,
                `comparison_json`  JSON NOT NULL,
                `content_hash`     CHAR(64) NOT NULL,

                `created_at`       DATETIME NOT NULL,
                `updated_at`       DATETIME NOT NULL,

                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_o58_items_identity`
                    (`store_account_id`, `source_order_id`, `source_item_id`),
                -- The comparison page\'s only item query, sort included, so it never files.
                KEY `ix_o58_items_order` (`store_account_id`, `source_order_id`, `position`),
                CONSTRAINT `fk_o58_items_order`
                    FOREIGN KEY (`store_account_id`, `source_order_id`)
                    REFERENCES `' . self::ORDERS . '` (`account_id`, `source_order_id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        $b->execute(
            'ALTER TABLE `' . self::ORDERS . '`
                ADD COLUMN `items_status`        VARCHAR(16) NOT NULL DEFAULT \'PENDING\',
                ADD COLUMN `items_count`         INT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN `items_attempts`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN `items_lease_owner`   CHAR(32) NULL,
                ADD COLUMN `items_leased_until`  DATETIME NULL,
                ADD COLUMN `items_retry_after`   DATETIME NULL,
                ADD COLUMN `items_error`         VARCHAR(255) NULL,
                ADD COLUMN `items_response_hash` CHAR(64) NULL,
                ADD COLUMN `items_response_json` JSON NULL,
                ADD COLUMN `items_synced_at`     DATETIME NULL,
                ADD KEY `ix_order58_orders_items_queue`
                    (`items_status`, `items_retry_after`, `id`),
                ADD CONSTRAINT `chk_order58_orders_items_status`
                    CHECK (`items_status` IN
                        (\'PENDING\',\'QUEUED\',\'PROCESSING\',\'SYNCED\',\'EMPTY\',\'FAILED\'))',
        );
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * Item rows are re-fetchable in principle — one API call per order — but only while the provider
     * still answers for those orders, and at 294 orders that is 294 calls nobody budgeted. So this
     * stops and says how much is there.
     *
     * The columns go only after the table, and only when it was empty, so a half-reverted schema cannot
     * leave item rows pointing at a parent that has forgotten about them.
     */
    public function down(MigrationBuilder $b): void
    {
        $rows = (int) $b->getDb()
            ->createCommand('SELECT COUNT(*) FROM `' . self::ITEMS . '`')
            ->queryScalar();

        if ($rows > 0) {
            throw new RuntimeException(
                self::ITEMS . ' holds ' . $rows . ' item row(s); rebuilding them costs one provider '
                . 'request per order. Empty the table deliberately if it really must go.',
            );
        }

        $b->execute('DROP TABLE `' . self::ITEMS . '`');

        $b->execute(
            'ALTER TABLE `' . self::ORDERS . '`
                DROP CONSTRAINT `chk_order58_orders_items_status`,
                DROP KEY `ix_order58_orders_items_queue`,
                DROP COLUMN `items_status`,
                DROP COLUMN `items_count`,
                DROP COLUMN `items_attempts`,
                DROP COLUMN `items_lease_owner`,
                DROP COLUMN `items_leased_until`,
                DROP COLUMN `items_retry_after`,
                DROP COLUMN `items_error`,
                DROP COLUMN `items_response_hash`,
                DROP COLUMN `items_response_json`,
                DROP COLUMN `items_synced_at`',
        );
    }
}
