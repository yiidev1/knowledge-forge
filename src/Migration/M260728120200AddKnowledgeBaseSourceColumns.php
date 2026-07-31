<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Constant\IndexType;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Maps a knowledge base to its Order58 source store (one store → exactly one knowledge base).
 *
 * The unique `(source_system, source_store_id)` index enforces the 1:1 mapping; NULLs are distinct, so
 * manually-created knowledge bases (no source) are unaffected. `agent_enabled` is the local on/off switch
 * for agent use, and `source_active` mirrors the store's Order58 status — together they gate provisioning
 * (an inactive store's vector store is deferred) and, in Phase 2, agent visibility.
 */
final class M260728120200AddKnowledgeBaseSourceColumns implements RevertibleMigrationInterface
{
    private const TABLE = '{{%knowledge_bases}}';

    public function up(MigrationBuilder $b): void
    {
        $b->addColumn(self::TABLE, 'source_system', ColumnBuilder::string(32)->null());
        $b->addColumn(self::TABLE, 'source_store_id', ColumnBuilder::bigint()->unsigned()->null());
        $b->addColumn(self::TABLE, 'source_name', ColumnBuilder::string(255)->null());
        $b->addColumn(self::TABLE, 'source_active', ColumnBuilder::tinyint(1)->null());
        $b->addColumn(self::TABLE, 'agent_enabled', ColumnBuilder::tinyint(1)->notNull()->defaultValue(1));
        $b->addColumn(self::TABLE, 'last_source_synced_at', ColumnBuilder::datetime()->null());
        $b->addColumn(self::TABLE, 'last_indexed_at', ColumnBuilder::datetime()->null());

        $b->createIndex(
            self::TABLE,
            'ux_knowledge_bases_source',
            ['source_system', 'source_store_id'],
            IndexType::UNIQUE,
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex(self::TABLE, 'ux_knowledge_bases_source');
        $b->dropColumn(self::TABLE, 'last_indexed_at');
        $b->dropColumn(self::TABLE, 'last_source_synced_at');
        $b->dropColumn(self::TABLE, 'agent_enabled');
        $b->dropColumn(self::TABLE, 'source_active');
        $b->dropColumn(self::TABLE, 'source_name');
        $b->dropColumn(self::TABLE, 'source_store_id');
        $b->dropColumn(self::TABLE, 'source_system');
    }
}
