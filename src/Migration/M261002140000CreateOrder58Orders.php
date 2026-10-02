<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Mirrors Order58 orders for a store and a date range.
 *
 * ## Hybrid, like the store mirror beside it
 *
 * Typed columns for what gets searched, filtered or totalled; `payload_json` for the complete original
 * order object. The source record carries ~70 fields, most of them incidental — `last_print_label`,
 * `delivery_noticed_at`, `mark_user_id` — and a column per field would be 70 migrations waiting to
 * happen the first time Order58 adds one. The full object is kept verbatim instead, so nothing is lost
 * and a field that later turns out to matter can be promoted to a column without a re-import.
 *
 * ## Identity is composite, deliberately
 *
 * `UNIQUE (account_id, source_order_id)`. Order ids *look* globally unique in the samples, but Order58
 * publishes no such contract, and the failure if that assumption is wrong is the worst kind: one store's
 * order silently overwriting another's. The composite key costs nothing and is correct either way.
 *
 * ## Money is DECIMAL, never float
 *
 * The source sends five-decimal strings (`"18.42000"`, `"0.08875"`), so the columns are
 * `DECIMAL(12,5)` and the values arrive as strings and stay strings. A float would make `tax_rate`
 * 0.08874999999 and every total it touches subtly wrong.
 *
 * ## Two kinds of time, kept apart
 *
 * `source_*` columns hold the provider's **Unix timestamps**, stored as BIGINT exactly as sent. The
 * local `synced_at`/`created_at`/`updated_at` are DATETIME in this application's own convention. Mixing
 * them in one type is how a display ends up showing 1970 or a sort puts last week before last year.
 *
 * ## `reservation_phone` is a column because other things will need it
 *
 * It lives inside `data` — itself a JSON-encoded *string*, not an object — so reading it means decoding
 * twice. Doing that in every future query is both slow and easy to get wrong, and demo URL generation
 * already needs it, so it is extracted once at write time.
 *
 * ## No delete path
 *
 * An order absent from a date-range response means it was not in that range, never that it stopped
 * existing. There is no sweep here and no `deactivateNotSeen` equivalent — unlike the store mirror,
 * which scans the full account list and can therefore tell.
 */
final class M261002140000CreateOrder58Orders implements RevertibleMigrationInterface
{
    private const ORDERS = 'order58_orders';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'CREATE TABLE `' . self::ORDERS . '` (
                `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                -- Identity. account_id is the store\'s Order58 source id, not a local row id.
                `account_id`          BIGINT UNSIGNED NOT NULL,
                `source_order_id`     BIGINT UNSIGNED NOT NULL,
                `uid`                 VARCHAR(64)  NULL,
                `seq`                 VARCHAR(32)  NULL,
                `customer_id`         BIGINT UNSIGNED NULL,
                `user_id`             BIGINT UNSIGNED NULL,
                `transaction_id`      VARCHAR(64)  NULL,

                -- What an operator searches and filters on.
                `type`                VARCHAR(32)  NULL,
                `payment_method`      VARCHAR(32)  NULL,
                `status`              VARCHAR(32)  NULL,
                `is_test`             TINYINT(1)   NOT NULL DEFAULT 0,
                `frontend`            TINYINT(1)   NOT NULL DEFAULT 0,
                `paid`                TINYINT(1)   NOT NULL DEFAULT 0,

                -- Extracted from the JSON-encoded `data` string; see the class docblock.
                `reservation_phone`   VARCHAR(32)  NULL,

                -- Money. Strings in, strings out; the source sends five decimals.
                `seawolf_subtotal`    DECIMAL(12,5) NULL,
                `seawolf_cost`        DECIMAL(12,5) NULL,
                `total_cost`          DECIMAL(12,5) NULL,
                `discount`            DECIMAL(12,5) NULL,
                `total_amount`        DECIMAL(12,5) NULL,
                `shipping_fee`        DECIMAL(12,5) NULL,
                `tax`                 DECIMAL(12,5) NULL,
                `tax_rate`            DECIMAL(12,7) NULL,
                `service_fee`         DECIMAL(12,5) NULL,
                `surcharge`           DECIMAL(12,5) NULL,
                `tip`                 DECIMAL(12,5) NULL,

                -- Logistics worth querying without opening the payload.
                `promotion_code`      VARCHAR(64)  NULL,
                `address_id`          BIGINT UNSIGNED NULL,
                `delivery_id`         BIGINT UNSIGNED NULL,
                `call_id`             BIGINT UNSIGNED NULL,
                `print_status`        VARCHAR(32)  NULL,
                `pickup_delivery_at`  VARCHAR(32)  NULL,
                `timezone`            VARCHAR(64)  NULL,

                -- The provider\'s own clock, in its own units.
                `source_created_at`   BIGINT UNSIGNED NULL,
                `source_updated_at`   BIGINT UNSIGNED NULL,
                `dining_time`         BIGINT UNSIGNED NULL,
                `cancelled_at`        BIGINT UNSIGNED NULL,

                -- The complete original order object, nothing dropped.
                `payload_json`        JSON NOT NULL,

                -- sha256 of the canonical mapped record. Change detection does NOT use the source
                -- updated_at: the samples contain orders where created_at == updated_at, so it cannot be
                -- relied on to move whenever something else does.
                `content_hash`        CHAR(64) NOT NULL,

                -- This application\'s clock.
                `synced_at`           DATETIME NOT NULL,
                `created_at`          DATETIME NOT NULL,
                `updated_at`          DATETIME NOT NULL,

                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_order58_orders_account_order` (`account_id`, `source_order_id`),
                -- The list: one store, newest first.
                KEY `ix_order58_orders_account_created` (`account_id`, `source_created_at`),
                -- Order-id search across stores, and the phone lookup other modules will want.
                KEY `ix_order58_orders_source_order` (`source_order_id`),
                KEY `ix_order58_orders_phone` (`reservation_phone`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * These rows are a mirror, but not a cheap one: re-fetching them means replaying every store and
     * date range an operator ever synced, against an API that only answers six days at a time. Dropping
     * a populated table would be minutes of clicking to undo, so this stops and says how much is there.
     */
    public function down(MigrationBuilder $b): void
    {
        $rows = (int) $b->getDb()->createCommand('SELECT COUNT(*) FROM `' . self::ORDERS . '`')->queryScalar();

        if ($rows > 0) {
            throw new RuntimeException(
                self::ORDERS . ' holds ' . $rows . ' mirrored order(s); re-fetching them means replaying '
                . 'every store and date range by hand. Empty the table deliberately if it really must go.',
            );
        }

        $b->execute('DROP TABLE `' . self::ORDERS . '`');
    }
}
