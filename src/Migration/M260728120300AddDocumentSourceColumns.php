<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Constant\IndexType;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * The unified-document-model columns: a `source_type` provenance axis (order58_store_profile,
 * order58_knowledge, uploaded_pdf, uploaded_image, and — in later phases — uploaded_text, manual_text),
 * plus the source id / `_sync_hash` a generated document is keyed and change-detected by, a display title,
 * an enable/disable flag, and the last-indexed timestamp.
 *
 * The unique `(knowledge_base_id, source_type, source_ref)` index means one generated document per source
 * record (a changed record updates in place, never duplicates). `source_ref` is NULL for uploads, and a
 * NULL exempts the row from the unique index, so uploads stay unconstrained. Content dedupe within a KB is
 * still handled by the existing `dedupe_hash` generated column.
 */
final class M260728120300AddDocumentSourceColumns implements RevertibleMigrationInterface
{
    private const TABLE = '{{%documents}}';

    public function up(MigrationBuilder $b): void
    {
        $b->addColumn(self::TABLE, 'source_type', ColumnBuilder::string(48)->notNull()->defaultValue('uploaded_pdf'));
        $b->addColumn(self::TABLE, 'source_ref', ColumnBuilder::string(64)->null());
        $b->addColumn(self::TABLE, 'source_sync_hash', ColumnBuilder::char(64)->null());
        $b->addColumn(self::TABLE, 'title', ColumnBuilder::string(255)->null());
        $b->addColumn(self::TABLE, 'is_enabled', ColumnBuilder::tinyint(1)->notNull()->defaultValue(1));
        $b->addColumn(self::TABLE, 'last_indexed_at', ColumnBuilder::datetime()->null());

        // Backfill existing uploads: derive the source type from the ingestion kind and seed a display
        // title from the original filename. Table prefix is empty, so the literal name is safe.
        $b->execute(
            "UPDATE `documents` SET"
            . " `source_type` = IF(`kind` = 'image', 'uploaded_image', 'uploaded_pdf'),"
            . ' `title` = `original_filename`',
        );

        $b->createIndex(
            self::TABLE,
            'ux_documents_source',
            ['knowledge_base_id', 'source_type', 'source_ref'],
            IndexType::UNIQUE,
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex(self::TABLE, 'ux_documents_source');
        $b->dropColumn(self::TABLE, 'last_indexed_at');
        $b->dropColumn(self::TABLE, 'is_enabled');
        $b->dropColumn(self::TABLE, 'title');
        $b->dropColumn(self::TABLE, 'source_sync_hash');
        $b->dropColumn(self::TABLE, 'source_ref');
        $b->dropColumn(self::TABLE, 'source_type');
    }
}
