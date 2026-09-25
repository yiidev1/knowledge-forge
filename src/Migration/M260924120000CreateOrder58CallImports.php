<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * The two tables behind Manage Order58 Calls.
 *
 * Importing a call recording is an ordinary Audio-to-Text upload with a different origin, so nothing here
 * duplicates the audio schema: the recording itself, its transcript and its corrections all live where a
 * manual upload puts them. These two tables record only what the audio tables cannot — **which external
 * call a conversation came from**, so the same call is never imported twice, and what happened to the
 * attempts that did not produce one.
 *
 * ## Two tables, and why the third would be wrong
 *
 * A batch is one administrator pressing Sync. An item is one recording channel. The level in between —
 * "the call" — is deliberately **not** stored: a call is its own channel rows grouped by
 * `call_session_id`, and its status is those rows aggregated, exactly as the store page already
 * aggregates a conversation's job statuses into one row. A call table would hold a value derivable from
 * the item table and would need rewriting on every channel transition, which is a consistency bug
 * waiting for its first partial failure.
 *
 * ## Why not `integration_sync_runs`
 *
 * That table's `active_key` coalesces by `(type, scope_ref)`, so a second Sync click for the same store
 * would silently merge into the first — wrong, because the two clicks may select different calls. Its
 * `status` is a MySQL ENUM, so the states below would each need a migration, and its drainer stops early
 * when the **token** credentials are absent, which would couple recording import to an unrelated API's
 * configuration.
 */
final class M260924120000CreateOrder58CallImports implements RevertibleMigrationInterface
{
    private const BATCHES = 'order58_call_import_batches';
    private const IMPORTS = 'order58_call_imports';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'CREATE TABLE `' . self::BATCHES . '` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                -- The store chosen on the page, which is also the external account id the call list was
                -- read from. No foreign key: `order58_stores` is a mirror that a sync may rewrite or
                -- remove, and an import that already happened must not become unreadable because of it.
                `store_source_id` BIGINT UNSIGNED NOT NULL,
                `triggered_by` VARCHAR(16) NOT NULL DEFAULT \'MANUAL\',
                -- The administrator who pressed Sync. NULL is reserved for the scheduler, which has no
                -- acting user; nothing writes NULL today.
                `requested_by_admin_id` BIGINT NULL,
                `transcription_provider` VARCHAR(16) NOT NULL,
                `generate_ai_audio` TINYINT(1) NOT NULL DEFAULT 0,
                -- A SNAPSHOT of `order58_stores.company`, not a lookup. The mirror is rewritten wholesale
                -- by every store sync, so re-reading it when the worker runs would let a sync change what
                -- an already-queued batch fetches. This column is also the audit trail of the value that
                -- was actually sent.
                `recording_company` VARCHAR(100) NOT NULL,
                `call_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `ix_order58_call_import_batches_store` (`store_source_id`, `id`),
                CONSTRAINT `fk_order58_call_import_batches_admin`
                    FOREIGN KEY (`requested_by_admin_id`) REFERENCES `admin_users` (`id`)
                    ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT `chk_order58_call_import_batches_trigger`
                    CHECK (`triggered_by` IN (\'MANUAL\', \'AUTOMATIC\')),
                CONSTRAINT `chk_order58_call_import_batches_provider`
                    CHECK (`transcription_provider` IN (\'WHISPER\', \'DEEPGRAM\')),
                -- A blank code would build a request the provider cannot answer, and the page refuses one
                -- before it gets here. Enforced again where it cannot be bypassed.
                CONSTRAINT `chk_order58_call_import_batches_company`
                    CHECK (`recording_company` <> \'\')
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        $b->execute(
            'CREATE TABLE `' . self::IMPORTS . '` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `batch_id` BIGINT UNSIGNED NOT NULL,
                -- Repeated from the batch on purpose: it is half of the identity below, and a unique key
                -- that had to join to be evaluated would not be a unique key.
                `store_source_id` BIGINT UNSIGNED NOT NULL,
                -- The provider\'s call session id, verbatim, digits only. It becomes a URL path segment
                -- and a filename, so it is validated before it is ever written.
                `call_session_id` VARCHAR(32) NOT NULL,
                `channel` VARCHAR(16) NOT NULL,
                -- What the provider said, unparsed, so a format change is visible rather than lost.
                `call_time_raw` VARCHAR(64) NOT NULL,
                -- The date this recording is fetched with. Derived from THIS call\'s own time, never from
                -- the server\'s clock, so a retry after midnight still asks for the right day.
                `call_date` DATE NOT NULL,
                `order_id` VARCHAR(20) NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT \'PENDING\',
                `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `next_attempt_at` DATETIME NULL,
                `error_code` VARCHAR(64) NULL,
                -- Redacted and truncated before it is stored: this is rendered on a page, and provider
                -- bodies routinely echo the request that produced them.
                `error_message` VARCHAR(1000) NULL,
                -- Evidence for a refusal, kept so real call sizes can be measured against the upload
                -- limits before deciding whether either should move.
                `bytes` BIGINT UNSIGNED NULL,
                `duration_seconds` DECIMAL(9,2) NULL,
                -- What this import produced. A public id rather than a row id, and no foreign key: the
                -- audio tables belong to a module this one may not name, and a retention sweep may remove
                -- the conversation long before this history is archived.
                `conversation_public_id` CHAR(32) NULL,
                `claimed_at` DATETIME NULL,
                `completed_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                -- THE duplicate guard. The account is included because the provider has not confirmed
                -- whether a call session id is unique across accounts or only within one, and the
                -- conservative reading costs nothing.
                UNIQUE KEY `ux_order58_call_imports_identity`
                    (`store_source_id`, `call_session_id`, `channel`),
                -- The worker\'s queue: eligible rows, oldest first.
                KEY `ix_order58_call_imports_queue` (`status`, `next_attempt_at`, `id`),
                KEY `ix_order58_call_imports_batch` (`batch_id`),
                CONSTRAINT `fk_order58_call_imports_batch`
                    FOREIGN KEY (`batch_id`) REFERENCES `' . self::BATCHES . '` (`id`)
                    ON DELETE RESTRICT ON UPDATE RESTRICT,
                -- Lower case, because these are the CLIENT\'s words for the three files
                -- (`22342359-caller.wav`) and `RecordingChannel` stores them verbatim. The audio module\'s
                -- own `recording_type` is upper case for the same reason in reverse: it is ITS vocabulary,
                -- not the provider\'s, and the two are deliberately not the same enum.
                CONSTRAINT `chk_order58_call_imports_channel`
                    CHECK (`channel` IN (\'mixed\', \'caller\', \'callee\')),
                CONSTRAINT `chk_order58_call_imports_status`
                    CHECK (`status` IN (
                        \'PENDING\', \'FETCHING\', \'IMPORTED\', \'NOT_AVAILABLE\', \'TOO_LARGE\', \'FAILED\'
                    )),
                CONSTRAINT `chk_order58_call_imports_session`
                    CHECK (`call_session_id` REGEXP \'^[0-9]{1,20}$\')
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
    }

    /**
     * Refuses to drop a history that records real imports.
     *
     * These tables are the only record of which external call produced which conversation. Dropping them
     * would not delete a recording, but it would make every imported conversation anonymous and would let
     * the next sync import all of them again — so a reversal that would lose that link is refused rather
     * than performed, exactly as `M260918100000CreateAudioTtsRenditions` refuses to strand paid audio.
     */
    public function down(MigrationBuilder $b): void
    {
        $imported = (int) $b->getDb()->createCommand(
            'SELECT COUNT(*) FROM `' . self::IMPORTS . '` WHERE `conversation_public_id` IS NOT NULL',
        )->queryScalar();

        if ($imported > 0) {
            throw new RuntimeException(
                'migrate:down aborted: ' . $imported . ' recording(s) were imported from Order58 and this '
                . 'table is the only thing linking them to the call they came from. Dropping it would make '
                . 'them re-importable as duplicates. Export the table first if you really mean to.',
            );
        }

        // Children first: the foreign key is RESTRICT.
        $b->execute('DROP TABLE IF EXISTS `' . self::IMPORTS . '`');
        $b->execute('DROP TABLE IF EXISTS `' . self::BATCHES . '`');
    }
}
