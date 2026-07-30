<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Marks index files awaiting remote removal.
 *
 * Deleting or re-indexing a document must detach its files from the OpenAI vector store and delete the
 * uploaded files — remote calls that do not belong in a web request. Instead of a separate queue table,
 * the file rows themselves carry a `pending_removal` flag; the background cleanup drainer removes the
 * remote objects and then the rows. Active processing ignores flagged rows, so a re-index's old files do
 * not confuse the new run.
 */
final class M260726090000AddIndexFileRemovalFlag implements RevertibleMigrationInterface
{
    private const TABLE = '{{%document_index_files}}';

    public function up(MigrationBuilder $b): void
    {
        $b->addColumn(self::TABLE, 'pending_removal', ColumnBuilder::tinyint(1)->notNull()->defaultValue(0));
        $b->createIndex(self::TABLE, 'ix_index_files_removal', 'pending_removal');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex(self::TABLE, 'ix_index_files_removal');
        $b->dropColumn(self::TABLE, 'pending_removal');
    }
}
