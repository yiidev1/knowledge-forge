<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * A topic may now belong to one store, or to all of them.
 *
 * ## The identifier, and why it is not a foreign key
 *
 * `store_source_id` holds Order58's own store id — `order58_stores.source_id`, which is
 * `BIGINT UNSIGNED`, carries `ux_order58_stores_source`, and is distinct across all 241 mirrored rows.
 * It is the same value every other table here keys a store by: `order58_orders.account_id`,
 * `audio_conversations.store_source_id`, `practice_order_topics.store_source_id`. A local row id would
 * be the wrong choice twice over — it is not what any URL or sibling table carries, and the mirror
 * re-creates rows.
 *
 * No FK, deliberately, and for the same reason the rest of this schema has none into the mirror:
 * `order58_stores` is a table the importer upserts on a schedule. A constraint there would let a store
 * sync fail because of a topic, and would make a store that temporarily leaves the mirror take local
 * curation with it. The value is validated in the service against the live directory instead.
 *
 * ## NULL means global, and every existing topic is global
 *
 * The column is added NULL and nothing backfills it, so the two topics in this database — and any
 * other — keep exactly the behaviour they had. "Global" is not a sentinel value to be migrated to; it
 * is the absence of a restriction.
 *
 * ## Uniqueness becomes per scope
 *
 * The old key was `UNIQUE (live_name_key)` — one live name across the whole application. That is now
 * wrong: two stores may each want a topic called "Refunds".
 *
 * `live_store_key` exists to make the new key expressible. A plain `UNIQUE (live_name_key,
 * store_source_id)` would NOT stop two global topics sharing a name, because MySQL treats NULLs in a
 * unique index as distinct from each other — the one case the old key did catch. So the generated
 * column folds NULL to 0, and keeps the archived exemption the old key had by going NULL itself when
 * the row is archived.
 *
 *   live   global   "Refunds"  -> ('refunds', 0)     second one refused
 *   live   store 101           -> ('refunds', 101)   allowed beside the global one
 *   live   store 102           -> ('refunds', 102)   allowed beside both
 *   archived any               -> (NULL, NULL)       exempt, as before, so a name can be reused
 */
final class M261021120000AddTopicStoreScope implements RevertibleMigrationInterface
{
    private const TABLE = 'practice_topics';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`
                -- Order58\'s own store id. NULL is global, and is the default for every row that
                -- already exists.
                ADD COLUMN `store_source_id` BIGINT UNSIGNED NULL AFTER `description`,

                -- Only so the unique key below can say what it means; see the class docblock.
                ADD COLUMN `live_store_key` BIGINT UNSIGNED
                    GENERATED ALWAYS AS (
                        IF(`status` = \'ARCHIVED\', NULL, IFNULL(`store_source_id`, 0))
                    ) STORED,

                -- Listing one store\'s topics, and the assignable lookup, which asks for this store
                -- OR global and orders by name.
                ADD KEY `ix_practice_topics_store` (`store_source_id`, `status`, `name`)',
        );

        // Replaced rather than added to: one live name per SCOPE is the rule now, and leaving the old
        // key in place would keep enforcing the old one underneath it.
        $b->execute('ALTER TABLE `' . self::TABLE . '` DROP INDEX `ux_practice_topics_live_name`');
        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`
                ADD UNIQUE KEY `ux_practice_topics_live_name` (`live_name_key`, `live_store_key`)',
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $scoped = (int) $b->getDb()
            ->createCommand(
                'SELECT COUNT(*) FROM `' . self::TABLE . '` WHERE `store_source_id` IS NOT NULL',
            )
            ->queryScalar();

        if ($scoped > 0) {
            throw new RuntimeException(
                $scoped . ' topic(s) belong to a particular store. Dropping this column would make '
                . 'them global without anybody deciding that, and two of them may then share a name '
                . 'that the restored key forbids. Reassign them to Global deliberately first.',
            );
        }

        $b->execute('ALTER TABLE `' . self::TABLE . '` DROP INDEX `ux_practice_topics_live_name`');
        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`
                DROP KEY `ix_practice_topics_store`,
                DROP COLUMN `live_store_key`,
                DROP COLUMN `store_source_id`,
                ADD UNIQUE KEY `ux_practice_topics_live_name` (`live_name_key`)',
        );
    }
}
