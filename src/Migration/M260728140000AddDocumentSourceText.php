<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Stores the original submitted text of a manual-text document, so it can be edited later.
 *
 * Only manual-text documents populate it. Uploaded files and Order58-generated documents leave it NULL —
 * their editable/authoritative form is the file or the source record. The indexed artifact is always the
 * normalized text on disk, never this column.
 */
final class M260728140000AddDocumentSourceText implements RevertibleMigrationInterface
{
    private const TABLE = '{{%documents}}';

    public function up(MigrationBuilder $b): void
    {
        $b->addColumn(self::TABLE, 'source_text', ColumnBuilder::text(65535)->null());
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn(self::TABLE, 'source_text');
    }
}
