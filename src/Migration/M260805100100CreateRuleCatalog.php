<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * The canonical rule catalog: the deduplicated logical rules and the audit links from every raw source row.
 *
 * `rule_catalog_rules` holds one row per canonical rule. Identity is `canonical_hash` (UNIQUE) =
 * SHA-256(normalized_title + "\0" + normalized_description) — two source rules collapse into one canonical
 * rule ONLY when both their normalized title and description match, so multiple rules that merely share a title
 * (e.g. several "Moon Temple" rules with different bodies) stay separate. `description_hash` (normalized
 * description only) is a non-identity index used to surface *possible* duplicates for review — it never
 * auto-merges. Classification columns (`scope_type`, `classification_status`, …) are seeded to their pending
 * defaults here and driven by Phase 2/3; VARCHAR + CHECK (rather than native ENUM) keeps the value sets easy to
 * extend additively.
 *
 * `rule_catalog_sources` links each raw `order58_rule_records` row to exactly one canonical rule
 * (`order58_rule_record_id` UNIQUE), recording whether it is the `primary` source or an `exact_duplicate`.
 * Every duplicate raw row is preserved and audit-linked; none is ever discarded.
 *
 * Foreign keys use ON DELETE RESTRICT (audit rows are only ever soft-deactivated, never physically deleted).
 * TABLE_OPTIONS matches the existing tables (MySQL 8.0 / utf8mb4_0900_ai_ci). The table prefix is empty in this
 * app, so literal backticked names in raw SQL are safe (same as M260804130000). Aborts use PHP `throw`, not a
 * `0 DIV 0` SELECT, which under this server's sql_mode would not abort.
 */
final class M260805100100CreateRuleCatalog implements RevertibleMigrationInterface
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'CREATE TABLE `rule_catalog_rules` ('
            . ' `id` BIGINT AUTO_INCREMENT PRIMARY KEY,'
            . ' `canonical_hash` CHAR(64) NOT NULL,'
            . ' `description_hash` CHAR(64) NOT NULL,'
            . ' `title` VARCHAR(500) NOT NULL,'
            . ' `content` TEXT NOT NULL,'
            . ' `scope_type` VARCHAR(16) NOT NULL DEFAULT \'unresolved\','
            . ' `classification_status` VARCHAR(24) NOT NULL DEFAULT \'pending\','
            . ' `classification_confidence` DECIMAL(5,4) NULL,'
            . ' `detected_store_text` VARCHAR(500) NULL,'
            . ' `classification_reason` VARCHAR(1000) NULL,'
            . ' `reviewed_by_admin_id` BIGINT UNSIGNED NULL,'
            . ' `reviewed_at` DATETIME NULL,'
            . ' `is_active` TINYINT(1) NOT NULL DEFAULT 1,'
            . ' `created_at` DATETIME NOT NULL,'
            . ' `updated_at` DATETIME NOT NULL,'
            . ' UNIQUE KEY `ux_rule_catalog_rules_hash` (`canonical_hash`),'
            . ' KEY `ix_rule_catalog_rules_desc` (`description_hash`),'
            . ' KEY `ix_rule_catalog_rules_scope` (`scope_type`, `classification_status`),'
            . ' KEY `ix_rule_catalog_rules_active` (`is_active`),'
            . ' CONSTRAINT `chk_rule_catalog_scope` CHECK (`scope_type` IN (\'common\',\'store_specific\',\'unresolved\')),'
            . ' CONSTRAINT `chk_rule_catalog_status` CHECK (`classification_status` IN'
            . ' (\'pending\',\'auto_matched\',\'manually_matched\',\'suggested_common\',\'confirmed_common\',\'ambiguous\',\'unmatched\',\'ignored\'))'
            . ') ' . self::TABLE_OPTIONS,
        );

        $b->execute(
            'CREATE TABLE `rule_catalog_sources` ('
            . ' `id` BIGINT AUTO_INCREMENT PRIMARY KEY,'
            . ' `rule_catalog_rule_id` BIGINT NOT NULL,'
            . ' `order58_rule_record_id` BIGINT NOT NULL,'
            . ' `relation_type` VARCHAR(24) NOT NULL DEFAULT \'primary\','
            . ' `created_at` DATETIME NOT NULL,'
            . ' UNIQUE KEY `ux_rule_catalog_sources_record` (`order58_rule_record_id`),'
            . ' KEY `ix_rule_catalog_sources_rule` (`rule_catalog_rule_id`),'
            . ' CONSTRAINT `chk_rule_catalog_sources_relation` CHECK (`relation_type` IN (\'primary\',\'exact_duplicate\',\'manually_merged\')),'
            . ' CONSTRAINT `fk_rule_catalog_sources_rule` FOREIGN KEY (`rule_catalog_rule_id`)'
            . ' REFERENCES `rule_catalog_rules` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,'
            . ' CONSTRAINT `fk_rule_catalog_sources_record` FOREIGN KEY (`order58_rule_record_id`)'
            . ' REFERENCES `order58_rule_records` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE'
            . ') ' . self::TABLE_OPTIONS,
        );
    }

    public function down(MigrationBuilder $b): void
    {
        // Audit-safe: refuse once the catalog holds data; canonical rules and their source links are audit
        // records that a schema drop would destroy. Restore from the pre-migration mysqldump instead.
        $rows = (int) $b->getDb()->createCommand('SELECT COUNT(*) FROM `rule_catalog_rules`')->queryScalar();
        if ($rows > 0) {
            throw new RuntimeException(
                'migrate:down aborted: rule_catalog_rules holds canonical rules and dropping it would lose audit '
                . 'history. Restore from the pre-migration mysqldump instead.',
            );
        }

        $b->execute('DROP TABLE `rule_catalog_sources`');
        $b->execute('DROP TABLE `rule_catalog_rules`');
    }
}
