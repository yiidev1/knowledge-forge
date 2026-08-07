<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Adds `document_index_files (document_id, index_status)` — the correlated "usable completed snapshot" EXISTS used
 * by the canonical chat-eligibility SQL ({@see \App\KnowledgeBase\Infrastructure\KnowledgeBaseChatEligibilitySql})
 * filters on both `document_id` and `index_status`, and the only existing index is `(document_id, role)`, which
 * cannot serve the `index_status` predicate. Additive, non-unique, reversible.
 */
final class M260806100000AddIndexFilesStatusIndex implements RevertibleMigrationInterface
{
    private const TABLE = '{{%document_index_files}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createIndex(self::TABLE, 'ix_index_files_doc_status', ['document_id', 'index_status']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex(self::TABLE, 'ix_index_files_doc_status');
    }
}
