<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Order Testing: the demo orders an operator produced, and who set out to produce them.
 *
 * ## Two tables, because they answer to two different authorities
 *
 * `order_testing_demo_orders` is a **mirror of files a third party wrote**. Every row is derived from
 * one JSON document that an Order58 listener dropped on disk, and nothing in this application may
 * invent, correct or complete it. `order_testing_attempts` is the opposite: it is entirely ours, written
 * by this application at the moment an administrator clicks through to Order58, and it is the only place
 * that knows *who* was testing.
 *
 * Folding them together was considered and is wrong: a demo order exists whether or not anybody pressed
 * our button — the listener will happily write one for an order created from Order58's own UI — and an
 * attempt exists whether or not it ever produces an order. Neither is a nullable column on the other.
 *
 * ## One source order, many demo orders
 *
 * This is the shape production already proved: source order `16758551` produced `16630651`, `16630661`
 * and `16630671`. So identity is the **triple**, and `source_order_id` alone is deliberately NOT unique.
 * The history is the feature — a later demo order never replaces an earlier one.
 *
 * ## Money is DECIMAL and timestamps are the provider's own
 *
 * Both for the reasons `order58_orders` beside it already gives: the source sends five-decimal money as
 * strings, and a float would make every total it touches subtly wrong; and the source's clock is Unix
 * seconds, which is a different kind of thing from this application's DATETIME columns and is kept in
 * its own type so a sort can never mix them.
 *
 * ## `raw_payload`, so a missing field is never a re-import
 *
 * The typed columns are the ones the list and the detail page read. The complete original document is
 * kept beside them, so a field that turns out to matter later becomes a read, not a migration plus a
 * rescan of files that may by then have been rotated away.
 */
final class M261007100000CreateOrderTestingTables implements RevertibleMigrationInterface
{
    private const DEMO_ORDERS = 'order_testing_demo_orders';
    private const ATTEMPTS = 'order_testing_attempts';

    public function up(MigrationBuilder $b): void
    {
        // Attempts first: a demo order may point at one, and nothing points the other way at creation.
        $b->execute(
            'CREATE TABLE `' . self::ATTEMPTS . '` (
                `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `public_id`             CHAR(32) NOT NULL,

                -- What is being tested. Both are Order58 source ids, not local row ids.
                `store_source_id`       BIGINT UNSIGNED NOT NULL,
                `source_order_id`       BIGINT UNSIGNED NOT NULL,

                -- Who. A type AND an id, because the realms number their users separately and an id
                -- alone would collide the day a second realm is allowed in. Today the CHECK admits
                -- only ADMIN: that is the only realm this feature has, and widening it later is one
                -- ALTER, which is cheaper than a column that claims more than the code can honour.
                `initiated_by_type`     VARCHAR(16) NOT NULL,
                `initiated_by_id`       BIGINT UNSIGNED NOT NULL,

                `status`                VARCHAR(16) NOT NULL,

                -- `expires_at` is written at creation rather than computed at read time, so the window
                -- an attempt holds cannot change retroactively when the configured TTL is changed.
                `started_at`            DATETIME NOT NULL,
                `expires_at`            DATETIME NOT NULL,
                `matched_at`            DATETIME NULL,
                `completed_at`          DATETIME NULL,

                -- The FIRST demo order this attempt produced, by Order58 demo id — not a local row id,
                -- and not the only one it may be credited with. An attempt can produce several; they
                -- each carry `matched_attempt_id` pointing back here. This column exists so the attempt
                -- row alone answers "did anything come of it".
                `matched_demo_order_id` BIGINT UNSIGNED NULL,

                `created_at`            DATETIME NOT NULL,
                `updated_at`            DATETIME NOT NULL,

                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_order_testing_attempts_public` (`public_id`),
                -- The open-attempt lookup, which runs on every Demo URL click and every import.
                KEY `ix_order_testing_attempts_open`
                    (`store_source_id`, `source_order_id`, `status`, `expires_at`),
                KEY `ix_order_testing_attempts_actor` (`initiated_by_type`, `initiated_by_id`),
                CONSTRAINT `chk_order_testing_attempts_type`
                    CHECK (`initiated_by_type` IN (\'ADMIN\')),
                CONSTRAINT `chk_order_testing_attempts_status`
                    CHECK (`status` IN (\'STARTED\',\'MATCHED\',\'EXPIRED\',\'CANCELLED\'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        $b->execute(
            'CREATE TABLE `' . self::DEMO_ORDERS . '` (
                `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `public_id`             CHAR(32) NOT NULL,

                -- Identity. The triple is the whole key; see the class docblock.
                `store_source_id`       BIGINT UNSIGNED NOT NULL,
                `source_order_id`       BIGINT UNSIGNED NOT NULL,
                `demo_order_id`         BIGINT UNSIGNED NOT NULL,

                -- Attribution, and nullable on purpose. A demo order with no open attempt is shown as
                -- unmatched rather than guessed at; see OrderTestingAttemptService.
                `matched_attempt_id`    BIGINT UNSIGNED NULL,

                -- Who the demo order was placed for, as the document records it. Never used to
                -- authenticate anything.
                `customer_phone`        VARCHAR(32)  NULL,
                `customer_first_name`   VARCHAR(128) NULL,
                `customer_last_name`    VARCHAR(128) NULL,
                `customer_email`        VARCHAR(190) NULL,

                `order_type`            VARCHAR(32)  NULL,
                `payment_method`        VARCHAR(32)  NULL,
                `status`                VARCHAR(32)  NULL,

                -- Money. Strings in, strings out.
                `subtotal`              DECIMAL(12,5) NULL,
                `shipping_fee`          DECIMAL(12,5) NULL,
                `tax`                   DECIMAL(12,5) NULL,
                `tip`                   DECIMAL(12,5) NULL,
                `total_amount`          DECIMAL(12,5) NULL,

                `address`               VARCHAR(255) NULL,
                `city`                  VARCHAR(128) NULL,
                `state`                 VARCHAR(64)  NULL,
                `postal_code`           VARCHAR(32)  NULL,

                -- The provider\'s clock, in the provider\'s units.
                `order_created_at`      BIGINT UNSIGNED NULL,
                `order_updated_at`      BIGINT UNSIGNED NULL,

                -- Provenance. The basename only — never a path, so nothing here can be read back as
                -- one. `source_mtime` lets a rescan skip a file that has not moved.
                `source_filename`       VARCHAR(255) NOT NULL,
                `source_mtime`          BIGINT UNSIGNED NULL,

                -- sha256 of the raw document, so a re-import that changes nothing writes nothing.
                `content_hash`          CHAR(64) NOT NULL,

                -- The complete original document, nothing dropped.
                `raw_payload`           JSON NOT NULL,

                `imported_at`           DATETIME NOT NULL,
                `created_at`            DATETIME NOT NULL,
                `updated_at`            DATETIME NOT NULL,

                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_order_testing_demo_orders_identity`
                    (`store_source_id`, `source_order_id`, `demo_order_id`),
                UNIQUE KEY `ux_order_testing_demo_orders_public` (`public_id`),
                -- The store page asks this one once per render, for every order on the page.
                KEY `ix_order_testing_demo_orders_source` (`store_source_id`, `source_order_id`),
                KEY `ix_order_testing_demo_orders_attempt` (`matched_attempt_id`),
                CONSTRAINT `fk_order_testing_demo_orders_attempt`
                    FOREIGN KEY (`matched_attempt_id`) REFERENCES `' . self::ATTEMPTS . '` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * These rows look re-creatable — rescan the directory — but only for as long as the directory still
     * holds the files, and nothing in this application controls how long that is. The attempts are not
     * re-creatable at all: who clicked, and when, exists nowhere else.
     */
    public function down(MigrationBuilder $b): void
    {
        foreach ([self::DEMO_ORDERS, self::ATTEMPTS] as $table) {
            $rows = (int) $b->getDb()->createCommand('SELECT COUNT(*) FROM `' . $table . '`')->queryScalar();

            if ($rows > 0) {
                throw new RuntimeException(
                    $table . ' holds ' . $rows . ' row(s). Demo orders can only be rebuilt while the '
                    . 'source JSON files still exist, and test attributions cannot be rebuilt at all. '
                    . 'Empty the table deliberately if it really must go.',
                );
            }
        }

        $b->execute('DROP TABLE `' . self::DEMO_ORDERS . '`');
        $b->execute('DROP TABLE `' . self::ATTEMPTS . '`');
    }
}
