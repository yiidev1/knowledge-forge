<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Constant\IndexType;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Local mirror of the safe Order58 source data: stores (accounts), agents and knowledge records.
 *
 * These tables let Knowledge Forge render admin pages without calling the Integration API on every page
 * load. Each row keeps the source primary id, the last received `_sync_hash` (change detection), the
 * source status, a curated JSON snapshot (never any credential), and timestamps. `last_seen_sync_run_id`
 * is the mark-and-sweep marker: a full sync stamps every record it sees, and only after the final page
 * succeeds are unseen active records deactivated — so an interrupted run never wrongly deactivates data.
 *
 * There is deliberately no foreign key from knowledge records to stores: a knowledge record may arrive
 * before its store has been synced, and it must be preserved (and marked seen) rather than rejected.
 */
final class M260728120000CreateOrder58Mirrors implements RevertibleMigrationInterface
{
    private const STORES = '{{%order58_stores}}';
    private const AGENTS = '{{%order58_agents}}';
    private const KNOWLEDGE = '{{%order58_knowledge_records}}';
    private const OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            self::STORES,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                // The stable source store id — Order58 account.id.
                'source_id' => ColumnBuilder::bigint()->unsigned()->notNull(),
                'name' => ColumnBuilder::string(255)->notNull(),
                'company' => ColumnBuilder::string(255)->null(),
                'active' => ColumnBuilder::tinyint(1)->notNull()->defaultValue(0),
                // Curated safe fields only — see the store-profile formatter. Never a credential.
                'snapshot_json' => ColumnBuilder::json()->null(),
                'sync_hash' => ColumnBuilder::char(64)->notNull(),
                // Accounts have no reliable source-updated timestamp, so this is usually NULL.
                'source_updated_at' => ColumnBuilder::datetime()->null(),
                'synced_at' => ColumnBuilder::datetime()->notNull(),
                // Mark-and-sweep run marker; signed to match integration_sync_runs.id (no FK — soft ref).
                'last_seen_sync_run_id' => ColumnBuilder::bigint()->null(),
                'created_at' => ColumnBuilder::datetime()->notNull(),
                'updated_at' => ColumnBuilder::datetime()->notNull(),
            ],
            self::OPTIONS,
        );
        $b->createIndex(self::STORES, 'ux_order58_stores_source', 'source_id', IndexType::UNIQUE);
        $b->createIndex(self::STORES, 'ix_order58_stores_active', 'active');

        $b->createTable(
            self::AGENTS,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                // The stable source agent identity — Order58 admin_id.
                'admin_id' => ColumnBuilder::bigint()->unsigned()->notNull(),
                'username' => ColumnBuilder::string(150)->notNull(),
                'first_name' => ColumnBuilder::string(150)->null(),
                'last_name' => ColumnBuilder::string(150)->null(),
                'email_address' => ColumnBuilder::string(255)->null(),
                'contact_number' => ColumnBuilder::string(64)->null(),
                'role' => ColumnBuilder::string(32)->null(),
                'status' => ColumnBuilder::string(32)->notNull(),
                // Stored so the Phase 2 agent login can admit only user_type == 'agent'.
                'user_type' => ColumnBuilder::string(32)->notNull(),
                // Employer/profile data ONLY. Never used to authorize store access.
                'account_id' => ColumnBuilder::bigint()->null(),
                'snapshot_json' => ColumnBuilder::json()->null(),
                'sync_hash' => ColumnBuilder::char(64)->notNull(),
                'source_modified_at' => ColumnBuilder::datetime()->null(),
                'synced_at' => ColumnBuilder::datetime()->notNull(),
                'last_seen_sync_run_id' => ColumnBuilder::bigint()->null(),
                'created_at' => ColumnBuilder::datetime()->notNull(),
                'updated_at' => ColumnBuilder::datetime()->notNull(),
            ],
            self::OPTIONS,
        );
        $b->createIndex(self::AGENTS, 'ux_order58_agents_admin', 'admin_id', IndexType::UNIQUE);
        $b->createIndex(self::AGENTS, 'ix_order58_agents_status', ['status', 'user_type']);

        $b->createTable(
            self::KNOWLEDGE,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                // The stable source record id — knowledge.id.
                'source_id' => ColumnBuilder::bigint()->unsigned()->notNull(),
                // Owning store — knowledge.store, which references account.id.
                'store_source_id' => ColumnBuilder::bigint()->unsigned()->notNull(),
                'title' => ColumnBuilder::string(500)->notNull(),
                'content' => ColumnBuilder::text()->notNull(),
                'knowledge_number' => ColumnBuilder::string(64)->null(),
                'keyword' => ColumnBuilder::string(255)->null(),
                'type' => ColumnBuilder::string(64)->null(),
                'active' => ColumnBuilder::tinyint(1)->notNull()->defaultValue(1),
                'snapshot_json' => ColumnBuilder::json()->null(),
                'sync_hash' => ColumnBuilder::char(64)->notNull(),
                'source_created_at' => ColumnBuilder::datetime()->null(),
                'source_updated_at' => ColumnBuilder::datetime()->null(),
                'synced_at' => ColumnBuilder::datetime()->notNull(),
                'last_seen_sync_run_id' => ColumnBuilder::bigint()->null(),
                'created_at' => ColumnBuilder::datetime()->notNull(),
                'updated_at' => ColumnBuilder::datetime()->notNull(),
            ],
            self::OPTIONS,
        );
        $b->createIndex(self::KNOWLEDGE, 'ux_order58_knowledge_source', 'source_id', IndexType::UNIQUE);
        $b->createIndex(self::KNOWLEDGE, 'ix_order58_knowledge_store', ['store_source_id', 'active']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::KNOWLEDGE);
        $b->dropTable(self::AGENTS);
        $b->dropTable(self::STORES);
    }
}
