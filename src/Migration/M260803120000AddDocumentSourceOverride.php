<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Local override flag for Order58-generated documents.
 *
 * When set, Order58 synchronization continues to update mirror tables but must not overwrite the
 * document's title or canonical local file until an administrator explicitly resets to Order58.
 */
final class M260803120000AddDocumentSourceOverride implements RevertibleMigrationInterface
{
    private const TABLE = '{{%documents}}';

    public function up(MigrationBuilder $b): void
    {
        $b->addColumn(
            self::TABLE,
            'is_source_overridden',
            ColumnBuilder::tinyint(1)->notNull()->defaultValue(0),
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn(self::TABLE, 'is_source_overridden');
    }
}
