<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Practice Orders: the training topics, and which source order each one is assigned to.
 *
 * ## Two tables, because a topic outlives its assignments
 *
 * `practice_topics` is a small master list an administrator curates — "Change Dish", "Sushi Any Roll 2".
 * `practice_order_topics` says which source order is being practised under which topic. Folding the
 * name into the assignment row was considered and is wrong: renaming a topic would then mean rewriting
 * every row that used it, and archiving one would mean choosing between losing the name and keeping a
 * duplicate of it on every order.
 *
 * ## The topic belongs to the SOURCE order, never to a demo order
 *
 * One source order produces many demo orders — production already shows four for `16758551` — and they
 * are all attempts at the same piece of training. Storing the topic per demo order would make "what was
 * this trainee practising" answerable differently depending on which attempt you opened. So the
 * assignment keys on the source order, and every demo order beneath it inherits that answer by being
 * beneath it.
 *
 * ## Identity is the pair, as it is everywhere else in this application
 *
 * `(store_source_id, source_order_id)`. `order58_orders` carries the same rule as
 * `ux_order58_orders_account_order` and states the reason: Order58 publishes no contract that order ids
 * are unique across stores, and the failure if that assumption is wrong is one merchant's order
 * silently wearing another merchant's topic. The UNIQUE key below is also the business rule — **one
 * source order has at most one topic** — so a reassignment is an upsert rather than a delete and an
 * insert, and two administrators assigning at the same moment cannot produce two rows.
 *
 * ## No foreign key to the orders mirror, on purpose
 *
 * `order58_orders` is a mirror of a third party's table and carries no foreign keys at all; a resync
 * must never be blocked by a row this application added beside it. The pair is checked in the service
 * instead, against the same store the request is scoped to.
 *
 * ## Archiving frees the name
 *
 * `live_name_key` is NULL for an archived topic, and MySQL treats NULLs in a unique index as distinct.
 * So "Change Dish" can be archived and later created afresh, while two live topics still cannot share a
 * name — including when they differ only by case or surrounding space, which is what `name_key` is for.
 * Archived rows keep their own `name`, so a two-year-old assignment still reads as what it was.
 */
final class M261014120000CreatePracticeTopics implements RevertibleMigrationInterface
{
    private const TOPICS = 'practice_topics';
    private const ASSIGNMENTS = 'practice_order_topics';

    public function up(MigrationBuilder $b): void
    {
        // Topics first: an assignment points at one, and nothing points the other way at creation.
        $b->execute(
            'CREATE TABLE `' . self::TOPICS . '` (
                `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                -- What an administrator typed, kept exactly as typed for display.
                `name`                  VARCHAR(120) NOT NULL,

                -- The same name folded for comparison: trimmed and lowercased by the application.
                -- Stored rather than computed so the uniqueness rule is the database\'s, not a habit.
                `name_key`              VARCHAR(120) NOT NULL,

                `description`           VARCHAR(500) NULL,

                -- ACTIVE   assignable, listed in pickers
                -- INACTIVE kept, not offered for new assignments
                -- ARCHIVED soft-deleted; existing assignments keep working, the name frees itself
                `status`                VARCHAR(16) NOT NULL DEFAULT \'ACTIVE\',

                -- Who created it. Topic Management is administrators only, so this is an
                -- `admin_users.id` and nothing else; no polymorphic actor is needed here.
                `created_by_admin_id`   BIGINT UNSIGNED NULL,

                `archived_at`           DATETIME NULL,
                `created_at`            DATETIME NOT NULL,
                `updated_at`            DATETIME NOT NULL,

                -- NULL once archived, and NULLs do not collide in a MySQL unique index. That is the
                -- whole mechanism by which an archived name becomes available again.
                `live_name_key`         VARCHAR(120)
                                        GENERATED ALWAYS AS (
                                            IF(`status` = \'ARCHIVED\', NULL, `name_key`)
                                        ) STORED,

                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_practice_topics_live_name` (`live_name_key`),
                KEY `ix_practice_topics_status` (`status`, `name`),

                CONSTRAINT `chk_practice_topics_status`
                    CHECK (`status` IN (\'ACTIVE\', \'INACTIVE\', \'ARCHIVED\'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        $b->execute(
            'CREATE TABLE `' . self::ASSIGNMENTS . '` (
                `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                -- The source order being practised. Both are Order58 source ids, not local row ids,
                -- and the pair is the identity — see the class docblock.
                `store_source_id`       BIGINT UNSIGNED NOT NULL,
                `source_order_id`       BIGINT UNSIGNED NOT NULL,

                `topic_id`              BIGINT UNSIGNED NOT NULL,

                -- Who assigned it. Administrators and operation users may both do this, and they are
                -- numbered by different systems, so the pair carries the realm with the id — the same
                -- shape `order_testing_attempts` already uses for `initiated_by_*`.
                `assigned_by_type`      VARCHAR(16) NOT NULL,
                `assigned_by_id`        BIGINT UNSIGNED NOT NULL,

                `created_at`            DATETIME NOT NULL,
                `updated_at`            DATETIME NOT NULL,

                PRIMARY KEY (`id`),

                -- The business rule, enforced here rather than in PHP: one topic per source order.
                UNIQUE KEY `ux_practice_order_topics_order` (`store_source_id`, `source_order_id`),

                -- Filtering a store\'s orders by topic, and counting a topic\'s assignments.
                KEY `ix_practice_order_topics_topic` (`topic_id`),

                -- RESTRICT, not CASCADE: archiving is the delete path for a topic, so a row here can
                -- never be orphaned by one disappearing underneath it.
                CONSTRAINT `fk_practice_order_topics_topic`
                    FOREIGN KEY (`topic_id`) REFERENCES `' . self::TOPICS . '` (`id`)
                    ON DELETE RESTRICT ON UPDATE CASCADE,

                CONSTRAINT `chk_practice_order_topics_actor`
                    CHECK (`assigned_by_type` IN (\'ADMIN\', \'OPERATION\'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * A topic list could be retyped; which order was being practised under which topic could not. That
     * is a record of training somebody did, and it exists nowhere else.
     */
    public function down(MigrationBuilder $b): void
    {
        foreach ([self::ASSIGNMENTS, self::TOPICS] as $table) {
            $rows = (int) $b->getDb()->createCommand('SELECT COUNT(*) FROM `' . $table . '`')->queryScalar();

            if ($rows > 0) {
                throw new RuntimeException(
                    $table . ' holds ' . $rows . ' row(s). Topic assignments record which training a '
                    . 'practice order belongs to and cannot be rebuilt from anything else. Empty the '
                    . 'table deliberately if it really must go.',
                );
            }
        }

        // Child before parent: the assignment holds the foreign key.
        $b->execute('DROP TABLE `' . self::ASSIGNMENTS . '`');
        $b->execute('DROP TABLE `' . self::TOPICS . '`');
    }
}
