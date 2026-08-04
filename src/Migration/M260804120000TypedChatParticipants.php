<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Canonical conversation ownership: participant_type + participant_id.
 *
 * Replaces shared-admin participant_key=0 with typed rows so KF admin id N cannot collide with
 * Order58 agent id N. Keeps agent_admin_id temporarily (NULL for admin, agent id for agent).
 *
 * Up: backfills agents from agent_admin_id; shared admin (NULL) only when exactly one active admin.
 * Down: aborts if any KB has more than one admin-typed conversation (would break old unique index).
 * Does not delete or merge conversations. Prefer mysqldump restore for multi-admin rollback.
 */
final class M260804120000TypedChatParticipants implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'SELECT COUNT(*) INTO @kf_active_admins FROM `admin_users` WHERE `is_active` = 1',
        );
        $b->execute(
            'SELECT COUNT(*) INTO @kf_shared_admin FROM `conversations` WHERE `agent_admin_id` IS NULL',
        );
        $b->execute(
            'SELECT IF('
            . '@kf_shared_admin = 0 OR @kf_active_admins = 1,'
            . ' 1,'
            . " (SELECT 0 DIV 0 FROM (SELECT 'Typed participant backfill aborted: shared admin threads exist but active admin count is not exactly 1. Run chat:participant-backfill-report.') AS `_kf_err`)"
            . ') INTO @kf_typed_backfill_ok',
        );

        $b->execute(
            'ALTER TABLE `conversations`'
            . " ADD COLUMN `participant_type` VARCHAR(16) NULL COMMENT 'admin|agent' AFTER `agent_admin_id`,"
            . ' ADD COLUMN `participant_id` BIGINT UNSIGNED NULL AFTER `participant_type`',
        );

        $b->execute(
            "UPDATE `conversations` SET `participant_type` = 'agent', `participant_id` = `agent_admin_id`"
            . ' WHERE `agent_admin_id` IS NOT NULL',
        );

        $b->execute(
            "UPDATE `conversations` c"
            . " INNER JOIN (SELECT `id` AS `admin_id` FROM `admin_users` WHERE `is_active` = 1 ORDER BY `id` LIMIT 1) a"
            . " SET c.`participant_type` = 'admin', c.`participant_id` = a.`admin_id`"
            . ' WHERE c.`agent_admin_id` IS NULL AND @kf_shared_admin > 0 AND @kf_active_admins = 1',
        );

        $b->execute(
            'SELECT IF('
            . '(SELECT COUNT(*) FROM `conversations` WHERE `participant_type` IS NULL OR `participant_id` IS NULL OR `participant_id` < 1) = 0,'
            . ' 1,'
            . " (SELECT 0 DIV 0 FROM (SELECT 'Typed participant backfill left NULL/invalid rows') AS `_kf_err`)"
            . ') INTO @kf_typed_complete',
        );

        $b->execute(
            'ALTER TABLE `conversations`'
            . " MODIFY COLUMN `participant_type` VARCHAR(16) NOT NULL COMMENT 'admin|agent',"
            . ' MODIFY COLUMN `participant_id` BIGINT UNSIGNED NOT NULL,'
            . " ADD CONSTRAINT `chk_conversations_participant_type` CHECK (`participant_type` IN ('admin','agent')),"
            . ' ADD CONSTRAINT `chk_conversations_participant_id_positive` CHECK (`participant_id` > 0)',
        );

        $b->execute('DROP INDEX `ux_conversations_kb_participant` ON `conversations`');
        $b->execute('ALTER TABLE `conversations` DROP COLUMN `participant_key`');

        $b->execute(
            'CREATE UNIQUE INDEX `ux_conversations_kb_participant_typed`'
            . ' ON `conversations` (`knowledge_base_id`, `participant_type`, `participant_id`)',
        );
    }

    /**
     * Restores old participant_key uniqueness only when each KB has at most one admin thread.
     * Never deletes or merges typed admin conversations.
     */
    public function down(MigrationBuilder $b): void
    {
        $b->execute(
            'SELECT COUNT(*) INTO @kf_multi_admin_kb FROM ('
            . " SELECT `knowledge_base_id` FROM `conversations` WHERE `participant_type` = 'admin'"
            . ' GROUP BY `knowledge_base_id` HAVING COUNT(*) > 1'
            . ') `_kf_multi`',
        );
        $b->execute(
            'SELECT IF('
            . '@kf_multi_admin_kb = 0,'
            . ' 1,'
            . " (SELECT 0 DIV 0 FROM (SELECT 'migrate:down aborted: more than one admin conversation per knowledge base. Restoring the old participant_key unique index would collide. Restore from the pre-migration mysqldump instead.') AS `_kf_err`)"
            . ') INTO @kf_down_ok',
        );

        $b->execute('DROP INDEX `ux_conversations_kb_participant_typed` ON `conversations`');
        $b->execute(
            'ALTER TABLE `conversations`'
            . ' DROP CHECK `chk_conversations_participant_id_positive`,'
            . ' DROP CHECK `chk_conversations_participant_type`,'
            . ' DROP COLUMN `participant_id`,'
            . ' DROP COLUMN `participant_type`',
        );

        $b->execute(
            'ALTER TABLE `conversations`'
            . ' ADD COLUMN `participant_key` BIGINT UNSIGNED'
            . ' AS (COALESCE(`agent_admin_id`, 0)) STORED NOT NULL',
        );
        $b->execute(
            'CREATE UNIQUE INDEX `ux_conversations_kb_participant`'
            . ' ON `conversations` (`knowledge_base_id`, `participant_key`)',
        );
    }
}
