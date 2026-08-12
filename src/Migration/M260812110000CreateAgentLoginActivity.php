<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Local record of which Order58 agents have actually signed in to Knowledge Forge.
 *
 * Until now a successful agent login wrote nothing at all: the identity goes into the PHP session and
 * `RequireAgentMiddleware` never reloads it, so there was no way to answer "who has used this system, and
 * when". The two tables that look like they might answer it cannot — `auth_login_attempts` records only
 * *failures* and is cleared on success, and `conversations` only appears once an agent actually chats.
 *
 * One row per agent, not one per login: this is usage state (first seen, last seen, how often), not an audit
 * trail. A login event stream would be a different table with different retention concerns.
 *
 * Deliberately separate from `order58_agents`, which is a pure upstream mirror (`sync_hash`, `synced_at`,
 * `last_seen_sync_run_id`): mixing local activity into it would mean a sync could overwrite local truth, and
 * a mark-and-sweep could delete it.
 *
 * There is **no foreign key** to that mirror, for the same reason `order58_knowledge_records` has none to
 * stores: an agent can authenticate against Order58 before the agents sync has ever run, and a foreign key
 * would turn that into a failed login.
 *
 * `first_login_at` means the first login recorded *after this table exists*. Nothing is backfilled from
 * conversations or Order58 data — an inferred "first login" would be a different, weaker claim than the one
 * this column makes.
 */
final class M260812110000CreateAgentLoginActivity implements RevertibleMigrationInterface
{
    private const TABLE = 'agent_login_activity';
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(MigrationBuilder $b): void
    {
        // Raw SQL rather than the fluent builder because of the CHECK constraints, matching the
        // rule-classification and answer-score migrations. Column types mirror `order58_agents` so the two
        // join cleanly: admin_id is BIGINT UNSIGNED there, username VARCHAR(150).
        $b->execute(
            'CREATE TABLE `' . self::TABLE . '` ('
            . ' `id` BIGINT AUTO_INCREMENT PRIMARY KEY,'
            . ' `agent_admin_id` BIGINT UNSIGNED NOT NULL,'
            . ' `username` VARCHAR(150) NOT NULL,'
            . ' `display_name` VARCHAR(255) NOT NULL,'
            . ' `first_login_at` DATETIME NOT NULL,'
            . ' `last_login_at` DATETIME NOT NULL,'
            . ' `login_count` INT UNSIGNED NOT NULL DEFAULT 1,'
            . ' `created_at` DATETIME NOT NULL,'
            . ' `updated_at` DATETIME NOT NULL,'
            // One row per agent. This is also what makes the recorder's INSERT … ON DUPLICATE KEY UPDATE
            // race-safe: two simultaneous logins collide here and converge instead of duplicating.
            . ' UNIQUE KEY `ux_agent_login_activity_admin` (`agent_admin_id`),'
            . ' CONSTRAINT `chk_agent_login_activity_admin_id_positive` CHECK (`agent_admin_id` > 0),'
            . ' CONSTRAINT `chk_agent_login_activity_count_positive` CHECK (`login_count` > 0)'
            // No index on last_login_at: the project rule is to add one when EXPLAIN shows the need, and
            // this table holds one row per agent — tens, not millions.
            . ') ' . self::TABLE_OPTIONS,
        );
    }

    public function down(MigrationBuilder $b): void
    {
        // Login history cannot be reconstructed from anything else — refuse rather than destroy it.
        $rows = (int) $b->getDb()->createCommand('SELECT COUNT(*) FROM `' . self::TABLE . '`')->queryScalar();
        if ($rows > 0) {
            throw new RuntimeException(
                'migrate:down aborted: ' . self::TABLE . ' holds agent login history that cannot be rebuilt. '
                . 'Restore from the pre-migration mysqldump instead.',
            );
        }

        $b->execute('DROP TABLE `' . self::TABLE . '`');
    }
}
