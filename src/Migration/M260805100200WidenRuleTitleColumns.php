<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Widens the rule `title` columns from VARCHAR(500) to TEXT.
 *
 * Unlike Order58 knowledge records, a rule's `title` field can carry long free-text (a full rule statement runs
 * to several hundred characters), which overflowed VARCHAR(500) and failed the sync insert with SQLSTATE 22001.
 * The title is not indexed on either table, so TEXT has no cost. Additive/forward-only: the raw mirror
 * (`order58_rule_records`) and the canonical catalog (`rule_catalog_rules`) both store the title.
 *
 * down() narrows back to VARCHAR(500) only when no stored title would be truncated; otherwise it refuses rather
 * than silently losing data (restore from the pre-migration mysqldump if a true rollback is required).
 */
final class M260805100200WidenRuleTitleColumns implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE `order58_rule_records` MODIFY `title` TEXT NOT NULL');
        $b->execute('ALTER TABLE `rule_catalog_rules` MODIFY `title` TEXT NOT NULL');
    }

    public function down(MigrationBuilder $b): void
    {
        $overflowing = $this->count($b, 'SELECT COUNT(*) FROM `order58_rule_records` WHERE CHAR_LENGTH(`title`) > 500')
            + $this->count($b, 'SELECT COUNT(*) FROM `rule_catalog_rules` WHERE CHAR_LENGTH(`title`) > 500');

        if ($overflowing > 0) {
            throw new RuntimeException(sprintf(
                'migrate:down aborted: %d rule title(s) exceed 500 characters and narrowing the column would '
                . 'truncate them. Restore from the pre-migration mysqldump instead.',
                $overflowing,
            ));
        }

        $b->execute('ALTER TABLE `order58_rule_records` MODIFY `title` VARCHAR(500) NOT NULL');
        $b->execute('ALTER TABLE `rule_catalog_rules` MODIFY `title` VARCHAR(500) NOT NULL');
    }

    private function count(MigrationBuilder $b, string $sql): int
    {
        return (int) $b->getDb()->createCommand($sql)->queryScalar();
    }
}
