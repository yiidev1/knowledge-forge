<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Phase 2 classification schema: store aliases, rule↔store links, and the classification audit trail.
 *
 * `order58_store_aliases` maps store-name spelling variations / company names / domains to an Order58 store.
 * It is intentionally NOT globally unique on the normalized alias (two stores may share a name); the unique
 * key is `(store_source_id, normalized_alias)`.
 *
 * `rule_store_links` connects a canonical rule to a store, with the match method/status/confidence. Only a
 * `confirmed` link may later become a searchable store-specific document (Phase 3). Unique on
 * `(rule_catalog_rule_id, store_source_id)` so a repeated classification upserts rather than duplicates.
 *
 * `rule_classification_events` is an append-only audit of every meaningful state change (incl. a future
 * `store_id_conflict`). All foreign keys are ON DELETE RESTRICT — audit rows are only ever soft-deactivated,
 * never physically deleted. VARCHAR+CHECK (not native ENUM) keeps the value sets extensible. TABLE_OPTIONS
 * matches the existing tables (MySQL 8.0 / utf8mb4_0900_ai_ci); the table prefix is empty so literal backticked
 * names are safe.
 */
final class M260805110000CreateRuleClassification implements RevertibleMigrationInterface
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'CREATE TABLE `order58_store_aliases` ('
            . ' `id` BIGINT AUTO_INCREMENT PRIMARY KEY,'
            . ' `store_source_id` BIGINT UNSIGNED NOT NULL,'
            . ' `alias` VARCHAR(255) NOT NULL,'
            . ' `normalized_alias` VARCHAR(255) NOT NULL,'
            . ' `alias_type` VARCHAR(24) NOT NULL,'
            . ' `is_approved` TINYINT(1) NOT NULL DEFAULT 1,'
            . ' `created_by_admin_id` BIGINT UNSIGNED NULL,'
            . ' `created_at` DATETIME NOT NULL,'
            . ' `updated_at` DATETIME NOT NULL,'
            . ' UNIQUE KEY `ux_store_aliases_store_norm` (`store_source_id`, `normalized_alias`),'
            . ' KEY `ix_store_aliases_norm` (`normalized_alias`, `is_approved`),'
            . ' CONSTRAINT `chk_store_aliases_type` CHECK (`alias_type` IN'
            . ' (\'official_name\',\'company_name\',\'domain\',\'generated\',\'discovered\',\'manual\'))'
            . ') ' . self::TABLE_OPTIONS,
        );

        $b->execute(
            'CREATE TABLE `rule_store_links` ('
            . ' `id` BIGINT AUTO_INCREMENT PRIMARY KEY,'
            . ' `rule_catalog_rule_id` BIGINT NOT NULL,'
            . ' `store_source_id` BIGINT UNSIGNED NOT NULL,'
            . ' `knowledge_base_id` BIGINT NULL,'
            . ' `match_status` VARCHAR(16) NOT NULL,'
            . ' `match_method` VARCHAR(32) NOT NULL,'
            . ' `matched_text` VARCHAR(500) NULL,'
            . ' `confidence` DECIMAL(5,4) NULL,'
            . ' `is_primary` TINYINT(1) NOT NULL DEFAULT 0,'
            . ' `created_by_type` VARCHAR(16) NULL,'
            . ' `created_by_id` BIGINT UNSIGNED NULL,'
            . ' `created_at` DATETIME NOT NULL,'
            . ' `updated_at` DATETIME NOT NULL,'
            . ' UNIQUE KEY `ux_rule_store_links_rule_store` (`rule_catalog_rule_id`, `store_source_id`),'
            . ' KEY `ix_rule_store_links_store` (`store_source_id`, `match_status`),'
            . ' CONSTRAINT `chk_rule_store_links_status` CHECK (`match_status` IN (\'suggested\',\'confirmed\',\'rejected\')),'
            . ' CONSTRAINT `chk_rule_store_links_method` CHECK (`match_method` IN'
            . ' (\'source_store_id\',\'domain\',\'title_exact_alias\',\'description_exact_alias\',\'manual\',\'fuzzy_suggestion\')),'
            . ' CONSTRAINT `fk_rule_store_links_rule` FOREIGN KEY (`rule_catalog_rule_id`)'
            . ' REFERENCES `rule_catalog_rules` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE'
            . ') ' . self::TABLE_OPTIONS,
        );

        $b->execute(
            'CREATE TABLE `rule_classification_events` ('
            . ' `id` BIGINT AUTO_INCREMENT PRIMARY KEY,'
            . ' `rule_catalog_rule_id` BIGINT NOT NULL,'
            . ' `event_type` VARCHAR(48) NOT NULL,'
            . ' `old_status` VARCHAR(24) NULL,'
            . ' `new_status` VARCHAR(24) NULL,'
            . ' `message` VARCHAR(1000) NULL,'
            . ' `metadata_json` JSON NULL,'
            . ' `admin_user_id` BIGINT UNSIGNED NULL,'
            . ' `created_at` DATETIME NOT NULL,'
            . ' KEY `ix_rule_class_events_rule` (`rule_catalog_rule_id`, `id`),'
            . ' CONSTRAINT `fk_rule_class_events_rule` FOREIGN KEY (`rule_catalog_rule_id`)'
            . ' REFERENCES `rule_catalog_rules` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE'
            . ') ' . self::TABLE_OPTIONS,
        );
    }

    public function down(MigrationBuilder $b): void
    {
        foreach (['order58_store_aliases', 'rule_store_links', 'rule_classification_events'] as $table) {
            $rows = (int) $b->getDb()->createCommand('SELECT COUNT(*) FROM `' . $table . '`')->queryScalar();
            if ($rows > 0) {
                throw new RuntimeException(
                    'migrate:down aborted: ' . $table . ' holds classification/audit data that a drop would lose. '
                    . 'Restore from the pre-migration mysqldump instead.',
                );
            }
        }

        $b->execute('DROP TABLE `rule_classification_events`');
        $b->execute('DROP TABLE `rule_store_links`');
        $b->execute('DROP TABLE `order58_store_aliases`');
    }
}
