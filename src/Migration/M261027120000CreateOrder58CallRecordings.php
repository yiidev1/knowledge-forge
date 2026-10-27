<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Case Order call recordings: which call sessions an order has, and the WAV links of each.
 *
 * The Case Order API now returns, per order, `calls: {session_id, wav_download_urls: [...]}`. That
 * document is already kept in `order58_orders.payload_json`, but a JSON blob cannot be indexed, joined
 * or paged, and every store page would otherwise decode two hundred payloads to draw one column. These
 * two tables hold exactly what is needed to find and play a recording, and nothing else.
 *
 * ## Metadata only
 *
 * No audio is stored, here or on disk. A recording is fetched from the provider when somebody presses
 * Play or Download and streamed through; the database keeps the link that says where it is.
 *
 * ## Two tables, because the provider's shape has two levels
 *
 * An order can hold several call sessions (a call back, a transfer) and a session can hold several WAV
 * files. Collapsing the two would repeat the session on every file and leave nowhere to say "this
 * session, as the provider last listed it" — which is the unit a changed link is replaced within.
 *
 * ## Identity
 *
 * - A session is `(store, order, provider session id)`. `order58_orders.call_id` is a different value
 *   and is deliberately not used.
 * - A recording is `(session, sha256 of the exact URL)`. The URL is stored verbatim — never rebuilt from
 *   a store code, a date or a phone number — and the hash is what makes a repeated synchronisation a
 *   no-op rather than a duplicate. It is a hash because a 2 KB URL cannot be a key column.
 *
 * ## Nothing is deleted by a synchronisation
 *
 * `delisted_at` marks a link the provider replaced: a later listing of the same session named other
 * URLs and not this one. A response that omits a session, or sends an empty list, says nothing about
 * it and changes nothing. Rows go only when their order goes (the cascade below), exactly like
 * `order58_order_items`.
 *
 * ## Why the down() is allowed to drop
 *
 * Unlike demo orders or verifications, every row here is derived from `payload_json` and can be rebuilt
 * with `kf:order58:call-recordings:backfill --write` without a single provider request.
 */
final class M261027120000CreateOrder58CallRecordings implements RevertibleMigrationInterface
{
    private const SESSIONS = 'order58_call_sessions';
    private const RECORDINGS = 'order58_call_recordings';
    private const ORDERS = 'order58_orders';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'CREATE TABLE `' . self::SESSIONS . '` (
                `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                -- The order, by the pair every other order table uses. account_id is unique only
                -- together with the order number, so the store always travels with it.
                `store_source_id`  BIGINT UNSIGNED NOT NULL,
                `source_order_id`  BIGINT UNSIGNED NOT NULL,

                -- The provider\'s calls.session_id, digits only, kept as text: PHP turns numeric
                -- string keys into integers, and an id is not a number anyone adds up.
                `call_session_id`  VARCHAR(20) NOT NULL,

                `created_at`       DATETIME NOT NULL,
                `updated_at`       DATETIME NOT NULL,

                PRIMARY KEY (`id`),

                -- Duplicate prevention, and the order-to-session lookup by its (store, order) prefix:
                -- the paged store listing asks for the sessions of fifteen orders at once.
                UNIQUE KEY `ux_o58_call_sessions_order`
                    (`store_source_id`, `source_order_id`, `call_session_id`),

                -- "Which order does this call belong to?" — the one question asked by session alone.
                KEY `ix_o58_call_sessions_session` (`call_session_id`),

                CONSTRAINT `fk_o58_call_sessions_order`
                    FOREIGN KEY (`store_source_id`, `source_order_id`)
                    REFERENCES `' . self::ORDERS . '` (`account_id`, `source_order_id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,

                CONSTRAINT `chk_o58_call_sessions_session`
                    CHECK (REGEXP_LIKE(`call_session_id`, \'^[0-9]{1,20}$\'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        $b->execute(
            'CREATE TABLE `' . self::RECORDINGS . '` (
                `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                -- The local session row, not the provider\'s session id.
                `session_id`   BIGINT UNSIGNED NOT NULL,

                -- Where the provider listed this file, 0-based, as of its latest listing.
                `position`     SMALLINT UNSIGNED NOT NULL,

                -- Exactly as the API returned it. It carries a customer phone number in `name=`, so it
                -- is never rendered, never put in a data attribute and never logged.
                `url`          VARCHAR(2048) NOT NULL,
                `url_hash`     CHAR(64) NOT NULL,

                -- Set when a later listing of the same session replaced this link. NULL = current.
                `delisted_at`  DATETIME NULL,

                `created_at`   DATETIME NOT NULL,
                `updated_at`   DATETIME NOT NULL,

                PRIMARY KEY (`id`),

                -- Duplicate prevention, and the session-to-recording lookup by its prefix. Sorting by
                -- position happens over a handful of rows per order, so it needs no index of its own.
                UNIQUE KEY `ux_o58_call_recordings_url` (`session_id`, `url_hash`),

                CONSTRAINT `fk_o58_call_recordings_session`
                    FOREIGN KEY (`session_id`)
                    REFERENCES `' . self::SESSIONS . '` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,

                -- The fetcher refuses anything else; the table refuses to hold it in the first place.
                CONSTRAINT `chk_o58_call_recordings_https` CHECK (`url` LIKE \'https://%\'),
                CONSTRAINT `chk_o58_call_recordings_hash`
                    CHECK (REGEXP_LIKE(`url_hash`, \'^[0-9a-f]{64}$\'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('DROP TABLE `' . self::RECORDINGS . '`');
        $b->execute('DROP TABLE `' . self::SESSIONS . '`');
    }
}
