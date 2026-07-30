<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Constant\IndexType;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Knowledge bases, each backed by exactly one OpenAI vector store.
 *
 * Provisioning that vector store is a queued job, not something the web request does, so this table
 * carries a full set of worker state columns (attempts, claim marker, next-attempt time) alongside the
 * resulting identifier. Creating a knowledge base in the browser writes `vector_store_status = pending`
 * and returns; the cron worker takes it from there.
 */
final class M260724100100CreateKnowledgeBases implements RevertibleMigrationInterface
{
    private const TABLE = '{{%knowledge_bases}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            self::TABLE,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                'name' => ColumnBuilder::string(160)->notNull(),
                // Public identifier. Knowledge bases are addressed by slug rather than by id so that
                // URLs stay readable; child records are always scoped by the parent id in the query.
                'slug' => ColumnBuilder::string(160)->notNull(),
                'description' => ColumnBuilder::text()->null(),
                // Administrator-authored instructions. These are applied *below* the application's
                // immutable security preamble and can never override it.
                'system_instructions' => ColumnBuilder::text()->null(),

                'openai_vector_store_id' => ColumnBuilder::string(64)->null(),
                'vector_store_status' => ColumnBuilder::enum(
                    ['pending', 'provisioning', 'ready', 'failed'],
                )->notNull()->defaultValue('pending'),
                'provision_attempts' => ColumnBuilder::smallint()->unsigned()->notNull()->defaultValue(0),
                // Set when a worker claims the row; used to recover a job whose process died.
                'provision_started_at' => ColumnBuilder::datetime()->null(),
                // Exponential backoff marker. NULL means "eligible now".
                'provision_next_attempt_at' => ColumnBuilder::datetime()->null(),
                'vector_store_error_code' => ColumnBuilder::string(64)->null(),
                // Already redacted and truncated before it reaches this column.
                'vector_store_error' => ColumnBuilder::string(500)->null(),

                'status' => ColumnBuilder::enum(['active', 'archived'])->notNull()->defaultValue('active'),
                'created_at' => ColumnBuilder::datetime()->notNull(),
                'updated_at' => ColumnBuilder::datetime()->notNull(),
            ],
            $this->tableOptions(),
        );

        $b->createIndex(self::TABLE, 'ux_knowledge_bases_slug', 'slug', IndexType::UNIQUE);

        // A vector store belongs to exactly one knowledge base. The unique index is what stops a
        // botched reconciliation from attaching one store to two knowledge bases; NULLs are distinct in
        // MySQL, so any number of rows may still be awaiting provisioning.
        $b->createIndex(
            self::TABLE,
            'ux_knowledge_bases_vector_store',
            'openai_vector_store_id',
            IndexType::UNIQUE,
        );

        // Drives the worker's claim query: pending rows whose backoff has expired, oldest first.
        $b->createIndex(
            self::TABLE,
            'ix_knowledge_bases_provisioning',
            ['vector_store_status', 'provision_next_attempt_at', 'id'],
        );

        // Drives the knowledge-base list screen.
        $b->createIndex(self::TABLE, 'ix_knowledge_bases_status_name', ['status', 'name']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::TABLE);
    }

    private function tableOptions(): string
    {
        return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    }
}
