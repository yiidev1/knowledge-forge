<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Constant\IndexType;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Local raw mirror of the Order58 Rules API (`GET /api/integration/v1/rules`).
 *
 * One row per upstream rule, keyed by the stable source `id` (UNIQUE). Like the other Order58 mirrors it keeps
 * the last received `_sync_hash` (change detection), a curated JSON snapshot (never a credential), and the
 * mark-and-sweep marker `last_seen_sync_run_id` — a full sync stamps every record it sees and only after the
 * final page succeeds are unseen active records soft-deactivated (`is_active = 0`), never physically deleted.
 *
 * The current Rules API carries no store id; `source_store_id` is added now (nullable) so a future authoritative
 * store id can be stored without a schema redesign. There is deliberately no foreign key to `order58_stores`:
 * a rule may reference a store that is not (yet) mirrored, and it must be preserved rather than rejected.
 *
 * TABLE_OPTIONS matches M260728120000CreateOrder58Mirrors (MySQL 8.0 / utf8mb4_0900_ai_ci). `source_id` and
 * `source_store_id` are BIGINT UNSIGNED to match the other mirrors; `last_seen_sync_run_id` is signed BIGINT to
 * match `integration_sync_runs.id` (a soft reference, no FK).
 */
final class M260805100000CreateOrder58RuleRecords implements RevertibleMigrationInterface
{
    private const RULES = '{{%order58_rule_records}}';
    private const OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            self::RULES,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                // The stable source rule id — rules.id.
                'source_id' => ColumnBuilder::bigint()->unsigned()->notNull(),
                'type' => ColumnBuilder::string(64)->null(),
                'title' => ColumnBuilder::string(500)->notNull(),
                'description' => ColumnBuilder::text()->notNull(),
                'rule_keyword' => ColumnBuilder::string(255)->null(),
                'created_name' => ColumnBuilder::string(255)->null(),
                // Future-ready: an authoritative store id, once the Rules API provides one. Not populated yet.
                'source_store_id' => ColumnBuilder::bigint()->unsigned()->null(),
                // Curated safe fields only (the source record minus `_sync_hash`). Never a credential.
                'snapshot_json' => ColumnBuilder::json()->null(),
                'sync_hash' => ColumnBuilder::char(64)->notNull(),
                'source_created_at' => ColumnBuilder::datetime()->null(),
                'source_updated_at' => ColumnBuilder::datetime()->null(),
                'synced_at' => ColumnBuilder::datetime()->notNull(),
                // Mark-and-sweep run marker; signed to match integration_sync_runs.id (no FK — soft ref).
                'last_seen_sync_run_id' => ColumnBuilder::bigint()->null(),
                // Soft-deactivation flag; a disappeared source is marked inactive, never deleted (audit).
                'is_active' => ColumnBuilder::tinyint(1)->notNull()->defaultValue(1),
                'created_at' => ColumnBuilder::datetime()->notNull(),
                'updated_at' => ColumnBuilder::datetime()->notNull(),
            ],
            self::OPTIONS,
        );
        $b->createIndex(self::RULES, 'ux_order58_rules_source', 'source_id', IndexType::UNIQUE);
        $b->createIndex(self::RULES, 'ix_order58_rules_active', 'is_active');
        $b->createIndex(self::RULES, 'ix_order58_rules_store', 'source_store_id');
    }

    public function down(MigrationBuilder $b): void
    {
        // Audit-safe: refuse to drop the mirror once it holds data (raw source rules are never physically
        // deleted in normal operation). Restore from the pre-migration mysqldump instead.
        $rows = (int) $b->getDb()->createCommand('SELECT COUNT(*) FROM `order58_rule_records`')->queryScalar();
        if ($rows > 0) {
            throw new RuntimeException(
                'migrate:down aborted: order58_rule_records holds mirrored source rules and dropping the table '
                . 'would lose audit history. Restore from the pre-migration mysqldump instead.',
            );
        }

        $b->dropTable(self::RULES);
    }
}
