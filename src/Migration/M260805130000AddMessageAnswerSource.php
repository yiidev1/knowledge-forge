<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Adds `messages.answer_source` — a nullable audit field recording where a chat answer came from:
 * `store_knowledge`, `store_rule`, `common_rule` or `fallback`.
 *
 * Additive and nullable: existing assistant messages keep NULL (unknown source), and the value is derived at
 * answer time from the winning citation + retrieval stage. It is reconstructible metadata (not irreplaceable
 * audit), so down() simply drops the column.
 */
final class M260805130000AddMessageAnswerSource implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE `messages` ADD COLUMN `answer_source` VARCHAR(32) NULL AFTER `model`');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE `messages` DROP COLUMN `answer_source`');
    }
}
