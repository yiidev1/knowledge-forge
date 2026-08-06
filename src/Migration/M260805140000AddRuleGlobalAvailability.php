<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Adds `rule_catalog_rules.is_globally_available` — an explicit retrieval-availability flag, kept SEPARATE from
 * `classification_status` (which stays a reporting/scope concern).
 *
 * Every active rule defaults to globally available (DEFAULT 1), so after a sync every active canonical rule has a
 * global searchable projection unless an admin explicitly ignores/disables it (which sets this to 0). Additive
 * and reconstructible from classification + admin actions, so down() simply drops the column — no audit loss.
 */
final class M260805140000AddRuleGlobalAvailability implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE `rule_catalog_rules` ADD COLUMN `is_globally_available` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_active`');
        // Existing rows: every active, non-ignored rule becomes globally available; ignored/inactive do not.
        $b->execute("UPDATE `rule_catalog_rules` SET `is_globally_available` = 0 WHERE `is_active` = 0 OR `classification_status` = 'ignored'");
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE `rule_catalog_rules` DROP COLUMN `is_globally_available`');
    }
}
