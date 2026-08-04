<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Message editing + regeneration audit (chat "edit your question" feature).
 *
 * Adds to `messages`: `reply_to_message_id` (an assistant answer → the user question it answers),
 * `superseded_at` (a replaced answer, hidden from the live thread and OpenAI history but kept for audit),
 * `edited_at` + `edit_count` (on user questions; the latter is also the optimistic-lock version). A STORED
 * generated `active_answer_key` + UNIQUE `ux_messages_active_answer` guarantee at most one active
 * (non-superseded) answer per question. Adds `message_revisions` to preserve prior question text.
 *
 * Backfill links each existing assistant message to the nearest preceding user message in the same
 * conversation (deterministic by created_at, id). The migration **aborts with a clear PHP exception**
 * (nothing changed — the preflight runs before any DDL) when the data cannot support the invariants: an
 * assistant with no preceding question, or a question that would receive more than one active answer.
 * Defensive post-backfill asserts abort if a cross-conversation/self/non-user link somehow appears. Nothing
 * is silently skipped or repaired. `down()` refuses to run once any edit/revision/superseded data exists;
 * restore from the pre-migration mysqldump instead.
 *
 * TABLE_OPTIONS matches M260727100000CreateConversations, verified against MySQL 8.0 / utf8mb4_0900_ai_ci.
 * `reply_to_message_id` and `active_answer_key` are signed BIGINT to match `messages.id`; `edited_by_id` is
 * BIGINT UNSIGNED to match `conversations.participant_id` (admin_users.id and Order58 agent admin_id).
 *
 * NOTE: aborts use PHP `throw`, not a `0 DIV 0` SELECT — verified that under this server's sql_mode a bare
 * `SELECT 0 DIV 0` yields NULL (a warning, not an error), so it would NOT abort a migration on dirty data.
 */
final class M260804130000AddMessageEditingAndRevisions implements RevertibleMigrationInterface
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    /** Assistant messages with no preceding user question in the same conversation (by created_at, id). */
    private const ORPHAN_ASSISTANTS = 'SELECT COUNT(*) FROM `messages` a WHERE a.`role` = \'assistant\''
        . ' AND NOT EXISTS (SELECT 1 FROM `messages` u WHERE u.`conversation_id` = a.`conversation_id`'
        . ' AND u.`role` = \'user\' AND (u.`created_at` < a.`created_at` OR (u.`created_at` = a.`created_at` AND u.`id` < a.`id`)))';

    /** User questions that would receive more than one active answer once reply_to is backfilled. */
    private const MULTI_ANSWER_QUESTIONS = 'SELECT COUNT(*) FROM (SELECT `rid` FROM ('
        . ' SELECT (SELECT u.`id` FROM `messages` u WHERE u.`conversation_id` = a.`conversation_id` AND u.`role` = \'user\''
        . ' AND (u.`created_at` < a.`created_at` OR (u.`created_at` = a.`created_at` AND u.`id` < a.`id`))'
        . ' ORDER BY u.`created_at` DESC, u.`id` DESC LIMIT 1) AS `rid`'
        . ' FROM `messages` a WHERE a.`role` = \'assistant\') p'
        . ' WHERE `rid` IS NOT NULL GROUP BY `rid` HAVING COUNT(*) > 1) q';

    public function up(MigrationBuilder $b): void
    {
        // --- Preflight (BEFORE any DDL, so a bad dataset leaves the schema untouched) [A1][A6] ---
        $orphans = $this->count($b, self::ORPHAN_ASSISTANTS);
        if ($orphans > 0) {
            throw new RuntimeException(sprintf(
                'Migration aborted: %d assistant message(s) have no preceding user question. Nothing was '
                . 'changed. Review the messages table and resolve these before migrating.',
                $orphans,
            ));
        }
        $multi = $this->count($b, self::MULTI_ANSWER_QUESTIONS);
        if ($multi > 0) {
            throw new RuntimeException(sprintf(
                'Migration aborted: %d user question(s) would receive more than one active answer, so the '
                . 'ux_messages_active_answer unique index cannot be created. Nothing was changed. Resolve the '
                . 'duplicate answers manually (or restore from a clean dump) before migrating.',
                $multi,
            ));
        }

        // --- Additive columns + reply-to index ---
        $b->execute(
            'ALTER TABLE `messages`'
            . ' ADD COLUMN `reply_to_message_id` BIGINT NULL AFTER `model`,'
            . ' ADD COLUMN `superseded_at` DATETIME NULL AFTER `reply_to_message_id`,'
            . ' ADD COLUMN `edited_at` DATETIME NULL AFTER `superseded_at`,'
            . ' ADD COLUMN `edit_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `edited_at`',
        );
        $b->execute('CREATE INDEX `ix_messages_reply_to` ON `messages` (`reply_to_message_id`)');

        // --- Backfill reply_to = nearest preceding user (materialised derived table avoids MySQL 1093) ---
        $b->execute(
            'UPDATE `messages` a'
            . ' JOIN (SELECT a2.`id` AS aid, ('
            . '   SELECT u.`id` FROM `messages` u'
            . '   WHERE u.`conversation_id` = a2.`conversation_id` AND u.`role` = \'user\''
            . '   AND (u.`created_at` < a2.`created_at` OR (u.`created_at` = a2.`created_at` AND u.`id` < a2.`id`))'
            . '   ORDER BY u.`created_at` DESC, u.`id` DESC LIMIT 1) AS uid'
            . '   FROM `messages` a2 WHERE a2.`role` = \'assistant\') m ON m.aid = a.`id`'
            . ' SET a.`reply_to_message_id` = m.uid',
        );

        // --- Post-backfill asserts (defensive; preflight already guarantees these) ---
        $this->assertZero(
            $b,
            'SELECT COUNT(*) FROM `messages` WHERE `role` = \'assistant\' AND `reply_to_message_id` IS NULL',
            'assistant messages remained without a reply_to_message_id',
        );
        $this->assertZero(
            $b,
            'SELECT COUNT(*) FROM `messages` WHERE `reply_to_message_id` = `id`',
            'a self-referential reply_to_message_id was produced',
        );
        $this->assertZero(
            $b,
            'SELECT COUNT(*) FROM `messages` a JOIN `messages` r ON r.`id` = a.`reply_to_message_id` WHERE r.`conversation_id` <> a.`conversation_id`',
            'a cross-conversation reply_to_message_id was produced',
        );
        $this->assertZero(
            $b,
            'SELECT COUNT(*) FROM `messages` a JOIN `messages` r ON r.`id` = a.`reply_to_message_id` WHERE r.`role` <> \'user\'',
            'a reply_to_message_id points at a non-user message',
        );

        // --- One-active-answer invariant ---
        $b->execute(
            'ALTER TABLE `messages` ADD COLUMN `active_answer_key` BIGINT'
            . ' AS (CASE WHEN `role` = \'assistant\' AND `superseded_at` IS NULL THEN `reply_to_message_id` END) STORED',
        );
        $b->execute('CREATE UNIQUE INDEX `ux_messages_active_answer` ON `messages` (`active_answer_key`)');

        // --- Prior-version audit trail ---
        $b->execute(
            'CREATE TABLE `message_revisions` ('
            . ' `id` BIGINT AUTO_INCREMENT PRIMARY KEY,'
            . ' `message_id` BIGINT NOT NULL,'
            . ' `revision_number` INT UNSIGNED NOT NULL,'
            . ' `content` TEXT NOT NULL,'
            . ' `edited_by_type` VARCHAR(16) NOT NULL,'
            . ' `edited_by_id` BIGINT UNSIGNED NOT NULL,'
            . ' `created_at` DATETIME NOT NULL,'
            . ' CONSTRAINT `fk_message_revisions_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,'
            . ' CONSTRAINT `chk_message_revisions_by_type` CHECK (`edited_by_type` IN (\'admin\',\'agent\')),'
            . ' CONSTRAINT `chk_message_revisions_by_id_positive` CHECK (`edited_by_id` > 0),'
            . ' UNIQUE KEY `ux_message_revisions_msg_rev` (`message_id`, `revision_number`)'
            . ') ' . self::TABLE_OPTIONS,
        );
    }

    /**
     * Refuses to run once the feature has produced audit data (revisions, edits, or superseded answers) that
     * a schema drop would silently destroy. Otherwise reverses the DDL in dependency-safe order.
     */
    public function down(MigrationBuilder $b): void
    {
        $revisions = $this->count($b, 'SELECT COUNT(*) FROM `message_revisions`');
        $edited = $this->count($b, 'SELECT COUNT(*) FROM `messages` WHERE `edit_count` > 0 OR `edited_at` IS NOT NULL OR `superseded_at` IS NOT NULL');
        if ($revisions > 0 || $edited > 0) {
            throw new RuntimeException(
                'migrate:down aborted: message edit / revision / superseded-answer data exists and a schema '
                . 'drop would lose it. Restore from the pre-migration mysqldump instead.',
            );
        }

        $b->execute('DROP TABLE `message_revisions`');
        $b->execute('DROP INDEX `ux_messages_active_answer` ON `messages`');
        $b->execute('DROP INDEX `ix_messages_reply_to` ON `messages`');
        $b->execute(
            'ALTER TABLE `messages`'
            . ' DROP COLUMN `active_answer_key`,'
            . ' DROP COLUMN `edit_count`,'
            . ' DROP COLUMN `edited_at`,'
            . ' DROP COLUMN `superseded_at`,'
            . ' DROP COLUMN `reply_to_message_id`',
        );
    }

    private function count(MigrationBuilder $b, string $sql): int
    {
        return (int) $b->getDb()->createCommand($sql)->queryScalar();
    }

    private function assertZero(MigrationBuilder $b, string $countSql, string $problem): void
    {
        if ($this->count($b, $countSql) > 0) {
            throw new RuntimeException(
                'Migration aborted after backfill: ' . $problem . '. The added columns remain — restore from '
                . 'the pre-migration mysqldump and investigate before retrying.',
            );
        }
    }
}
