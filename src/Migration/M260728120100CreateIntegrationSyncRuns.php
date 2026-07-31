<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * The synchronization state machine. An admin Sync button inserts a `pending` row and returns; the cron
 * worker claims it (`pending → running`), processes pages, and lands it in `completed`,
 * `completed_with_warnings` or `failed`. No page or OpenAI work ever happens in the web request.
 *
 * The three primary operations — `stores`, `knowledge`, `agents` — are independent: their active-run
 * coalescing key includes the type, so a running Knowledge sync never blocks Sync Stores or Sync Agents.
 * Scoped `knowledge_store`/`rebuild_store` runs are keyed per store id.
 *
 * Coalescing is enforced by the database with the same generated-column trick as `documents.dedupe_hash`:
 * `active_key` is the hash of type+scope while a run is pending/running and NULL once it is terminal, under
 * a UNIQUE index. A second click of the same operation while one is active is rejected as a duplicate.
 */
final class M260728120100CreateIntegrationSyncRuns implements RevertibleMigrationInterface
{
    private const TABLE = '{{%integration_sync_runs}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            self::TABLE,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                // stores | knowledge | agents | knowledge_store | rebuild_store | health
                'type' => ColumnBuilder::string(32)->notNull(),
                // Store source id for scoped operations; NULL for the three full syncs.
                'scope_ref' => ColumnBuilder::bigint()->unsigned()->null(),
                'status' => ColumnBuilder::enum(
                    ['pending', 'running', 'completed', 'completed_with_warnings', 'failed'],
                )->notNull()->defaultValue('pending'),
                'attempts' => ColumnBuilder::smallint()->unsigned()->notNull()->defaultValue(0),
                'requested_by_admin_id' => ColumnBuilder::bigint()->null(),
                // Page cursor + counts (created/updated/unchanged/deactivated/skipped_missing_store/…).
                'progress_json' => ColumnBuilder::json()->null(),
                'claimed_at' => ColumnBuilder::datetime()->null(),
                'started_at' => ColumnBuilder::datetime()->null(),
                'completed_at' => ColumnBuilder::datetime()->null(),
                'next_attempt_at' => ColumnBuilder::datetime()->null(),
                'error_code' => ColumnBuilder::string(64)->null(),
                // Sanitized; also holds a warning summary for completed_with_warnings.
                'error_message' => ColumnBuilder::string(1000)->null(),
                'created_at' => ColumnBuilder::datetime()->notNull(),
                'updated_at' => ColumnBuilder::datetime()->notNull(),
            ],
            'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        // The worker's claim queue: eligible runs, oldest first.
        $b->createIndex(self::TABLE, 'ix_integration_sync_runs_status', ['status', 'next_attempt_at', 'id']);

        // Coalescing: only one active (pending|running) run per (type, scope). NULL once terminal, so any
        // number of completed/failed history rows may coexist. MigrationBuilder has no generated-column
        // API, so this is raw SQL — table prefix is empty in this application, so the literal name is safe.
        $b->execute(
            'ALTER TABLE `integration_sync_runs`'
            . ' ADD COLUMN `active_key` CHAR(64)'
            . " AS (IF(`status` IN ('pending','running'),"
            . " SHA2(CONCAT(`type`, ':', COALESCE(`scope_ref`, 0)), 256), NULL)) STORED",
        );
        $b->execute(
            'ALTER TABLE `integration_sync_runs`'
            . ' ADD UNIQUE INDEX `ux_integration_sync_runs_active` (`active_key`)',
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::TABLE);
    }
}
