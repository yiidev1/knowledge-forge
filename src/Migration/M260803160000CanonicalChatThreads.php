<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Canonical one-thread-per-store-and-participant model.
 *
 * 1. Merge duplicate conversations for the same (knowledge_base_id, COALESCE(agent_admin_id, 0)),
 *    moving messages into the oldest conversation and deleting duplicates.
 * 2. Add generated participant_key + unique index, and CHECK (agent_admin_id IS NULL OR > 0).
 *
 * Data merge is IRREVERSIBLE. {@see down()} only removes the index/CHECK/generated column.
 * Restore conversation grouping from mysqldump if a rollback of the merge is required.
 *
 * participant_key = 0 is reserved exclusively for the shared admin thread (agent_admin_id IS NULL).
 */
final class M260803160000CanonicalChatThreads implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        // Fail if any row uses the reserved admin sentinel or a negative id.
        $b->execute(
            'SELECT IF('
            . '(SELECT COUNT(*) FROM `conversations` WHERE `agent_admin_id` = 0 OR `agent_admin_id` < 0) = 0,'
            . ' 1,'
            . " (SELECT 0 DIV 0 FROM (SELECT 'Invalid agent_admin_id: must be NULL or > 0') AS `_kf_err`)"
            . ') INTO @kf_chat_thread_agent_check',
        );

        $b->execute('DROP TEMPORARY TABLE IF EXISTS `_kf_chat_dup_groups`');
        $b->execute(
            'CREATE TEMPORARY TABLE `_kf_chat_dup_groups` AS '
            . 'SELECT `knowledge_base_id`, COALESCE(`agent_admin_id`, 0) AS `participant_key`, '
            . 'MIN(`id`) AS `canonical_id` '
            . 'FROM `conversations` '
            . 'GROUP BY `knowledge_base_id`, COALESCE(`agent_admin_id`, 0) '
            . 'HAVING COUNT(*) > 1',
        );

        $b->execute(
            'UPDATE `messages` m '
            . 'INNER JOIN `conversations` c ON c.`id` = m.`conversation_id` '
            . 'INNER JOIN `_kf_chat_dup_groups` g '
            . '  ON g.`knowledge_base_id` = c.`knowledge_base_id` '
            . ' AND g.`participant_key` = COALESCE(c.`agent_admin_id`, 0) '
            . 'SET m.`conversation_id` = g.`canonical_id` '
            . 'WHERE m.`conversation_id` <> g.`canonical_id`',
        );

        $b->execute(
            'UPDATE `conversations` c '
            . 'INNER JOIN `_kf_chat_dup_groups` g ON g.`canonical_id` = c.`id` '
            . 'SET c.`last_message_at` = ('
            . '  SELECT MAX(m.`created_at`) FROM `messages` m WHERE m.`conversation_id` = c.`id`'
            . '), c.`updated_at` = UTC_TIMESTAMP()',
        );

        $b->execute(
            'DELETE c FROM `conversations` c '
            . 'INNER JOIN `_kf_chat_dup_groups` g '
            . '  ON g.`knowledge_base_id` = c.`knowledge_base_id` '
            . ' AND g.`participant_key` = COALESCE(c.`agent_admin_id`, 0) '
            . 'WHERE c.`id` <> g.`canonical_id`',
        );

        $b->execute('DROP TEMPORARY TABLE IF EXISTS `_kf_chat_dup_groups`');

        $b->execute(
            'ALTER TABLE `conversations`'
            . ' ADD COLUMN `participant_key` BIGINT UNSIGNED'
            . ' AS (COALESCE(`agent_admin_id`, 0)) STORED NOT NULL,'
            . ' ADD CONSTRAINT `chk_conversations_agent_admin_id_positive`'
            . ' CHECK (`agent_admin_id` IS NULL OR `agent_admin_id` > 0)',
        );
        $b->execute(
            'CREATE UNIQUE INDEX `ux_conversations_kb_participant`'
            . ' ON `conversations` (`knowledge_base_id`, `participant_key`)',
        );
    }

    /**
     * Removes uniqueness DDL only. Does NOT restore merged conversation rows — use mysqldump.
     */
    public function down(MigrationBuilder $b): void
    {
        $b->execute('DROP INDEX `ux_conversations_kb_participant` ON `conversations`');
        $b->execute(
            'ALTER TABLE `conversations`'
            . ' DROP CHECK `chk_conversations_agent_admin_id_positive`,'
            . ' DROP COLUMN `participant_key`',
        );
    }
}
