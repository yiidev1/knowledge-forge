<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Order Testing: whether a source order has been checked over, and the record of who changed that.
 *
 * ## Why this is not a column on something that already exists
 *
 * Verification is a statement about one **source order**, so it has to live on a row there is exactly
 * one of per order. Measured against this database before choosing: 4 orders carry more than one
 * conversation, 2 carry more than one demo order and 3 carry more than one attempt. A flag on any of
 * those tables would give one order several contradictory answers, and the person reading the page
 * would get whichever row happened to be fetched first.
 *
 * `order58_orders` is the one table with a row per order, and it is the wrong home for a different
 * reason: it is a mirror the Order58 importer upserts on a schedule. Local accountability must not sit
 * in a row whose lifecycle a third party owns, and no local workflow state lives there today.
 *
 * ## Identity is the pair, as everywhere else in this application
 *
 * `(store_source_id, source_order_id)`. `order58_orders` enforces the same pair in
 * `ux_order58_orders_account_order` and says why: Order58 publishes no contract that order ids are
 * unique across stores, and `source_order_id` alone carries a NON-unique index here. Today no order
 * number is reused across stores — but "today" is not a rule, and the failure if it ever is would be
 * one merchant's order wearing another merchant's verification.
 *
 * ## Two tables, state and history
 *
 * The state table answers the question the page asks twenty times a render: is this order verified?
 * One indexed lookup per page, and a UNIQUE key that makes a second opinion impossible.
 *
 * The events table answers a different question — who decided what, and when — which a state row
 * cannot, because each change overwrites the last actor. "An administrator verified it, an operation
 * user removed that, the same operation user verified it again" is a sequence, and sequences need rows.
 * It is append-only: nothing in the application updates or deletes an event.
 *
 * ## `version`, and why a tinyint flag still needs one
 *
 * Two people can have the same order open. Without a version, the second save silently overwrites a
 * decision the first person has already acted on, and neither of them ever learns. Every write is a
 * conditional UPDATE on this column — the same optimistic lock `audio_transcription_jobs.review_count`
 * already uses for transcript corrections — so a stale tab is refused and told to look again.
 *
 * ## No foreign key to the orders mirror
 *
 * Deliberate, and the same choice `practice_order_topics` made: the mirror carries no foreign keys at
 * all, and a resync must never be blocked by a row this application added beside it. The pair is
 * validated in the service against the store the request is already scoped to.
 */
final class M261015120000CreateOrderVerifications implements RevertibleMigrationInterface
{
    private const STATE = 'order_testing_order_verifications';
    private const EVENTS = 'order_testing_verification_events';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'CREATE TABLE `' . self::STATE . '` (
                `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                -- The source order this is about. Both are Order58 source ids, never local row ids,
                -- and only the pair identifies an order — see the class docblock.
                `store_source_id`       BIGINT UNSIGNED NOT NULL,
                `source_order_id`       BIGINT UNSIGNED NOT NULL,

                -- The answer itself. Default 0, so every order that already exists starts unverified
                -- and nothing is retrospectively claimed to have been checked.
                `is_verified`           TINYINT(1) NOT NULL DEFAULT 0,

                -- Who put it in the verified state, and when. NULL while it is not verified: there is
                -- no current verifier then, and saying otherwise would credit somebody with a state
                -- that has since been taken away. The history below keeps what they did.
                `verified_at`           DATETIME NULL,
                `verified_by_type`      VARCHAR(16) NULL,
                `verified_by_id`        BIGINT UNSIGNED NULL,

                -- Who last changed it either way, and when. Always populated: a row exists only
                -- because somebody did something.
                `changed_at`            DATETIME NOT NULL,
                `changed_by_type`       VARCHAR(16) NOT NULL,
                `changed_by_id`         BIGINT UNSIGNED NOT NULL,

                -- The optimistic lock. Advances on every write, including one that lands on the state
                -- it was already in, so "nothing changed" and "somebody else changed it back" stay
                -- distinguishable.
                `version`               INT UNSIGNED NOT NULL DEFAULT 0,

                `created_at`            DATETIME NOT NULL,
                `updated_at`            DATETIME NOT NULL,

                PRIMARY KEY (`id`),

                -- The business rule, enforced here rather than in PHP: one verification state per
                -- source order. It also makes the write an upsert rather than a read-then-insert, so
                -- two people verifying at the same moment cannot produce two rows.
                UNIQUE KEY `ux_order_testing_verifications_order` (`store_source_id`, `source_order_id`),

                -- Listing one store\'s verified orders.
                KEY `ix_order_testing_verifications_store` (`store_source_id`, `is_verified`),

                CONSTRAINT `chk_order_testing_verifications_flag`
                    CHECK (`is_verified` IN (0, 1)),

                -- Administrators and operation users may both do this, and the two realms are numbered
                -- by different systems, so the realm travels with the id — the same shape
                -- `order_testing_attempts.initiated_by_*` and `practice_order_topics.assigned_by_*`
                -- already use. Agents cannot reach these routes and are absent on purpose.
                CONSTRAINT `chk_order_testing_verifications_changed_by`
                    CHECK (`changed_by_type` IN (\'ADMIN\', \'OPERATION\')),
                CONSTRAINT `chk_order_testing_verifications_verified_by`
                    CHECK (`verified_by_type` IS NULL OR `verified_by_type` IN (\'ADMIN\', \'OPERATION\')),

                -- The flag and its attribution cannot disagree. Verified means somebody is named for
                -- it; unverified means nobody is.
                CONSTRAINT `chk_order_testing_verifications_consistent`
                    CHECK (
                        (`is_verified` = 0
                            AND `verified_at` IS NULL
                            AND `verified_by_type` IS NULL
                            AND `verified_by_id` IS NULL)
                        OR (`is_verified` = 1
                            AND `verified_at` IS NOT NULL
                            AND `verified_by_type` IS NOT NULL
                            AND `verified_by_id` IS NOT NULL)
                    )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        $b->execute(
            'CREATE TABLE `' . self::EVENTS . '` (
                `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                -- Keyed by the order, not by the state row\'s id: the identity of an order is the pair
                -- everywhere else in this application, and a history that outlives its state row is
                -- the point of keeping one.
                `store_source_id`       BIGINT UNSIGNED NOT NULL,
                `source_order_id`       BIGINT UNSIGNED NOT NULL,

                `action`                VARCHAR(16) NOT NULL,

                `actor_type`            VARCHAR(16) NOT NULL,
                `actor_id`              BIGINT UNSIGNED NOT NULL,

                `created_at`            DATETIME NOT NULL,

                PRIMARY KEY (`id`),

                -- One order\'s history, newest last. The id breaks ties within the same second, which
                -- a verify-then-unverify can easily land in.
                KEY `ix_order_testing_verification_events_order`
                    (`store_source_id`, `source_order_id`, `id`),

                -- What one person did across every store, for an accountability question.
                KEY `ix_order_testing_verification_events_actor` (`actor_type`, `actor_id`),

                CONSTRAINT `chk_order_testing_verification_events_action`
                    CHECK (`action` IN (\'VERIFIED\', \'UNVERIFIED\')),
                CONSTRAINT `chk_order_testing_verification_events_actor`
                    CHECK (`actor_type` IN (\'ADMIN\', \'OPERATION\'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * A verification is somebody stating that they checked an order, and the history is the only record
     * that they did. Neither can be rebuilt from anything else in this database.
     */
    public function down(MigrationBuilder $b): void
    {
        foreach ([self::EVENTS, self::STATE] as $table) {
            $rows = (int) $b->getDb()->createCommand('SELECT COUNT(*) FROM `' . $table . '`')->queryScalar();

            if ($rows > 0) {
                throw new RuntimeException(
                    $table . ' holds ' . $rows . ' row(s). Order verifications record that a person '
                    . 'checked an order, and exist nowhere else. Empty the table deliberately if it '
                    . 'really must go.',
                );
            }
        }

        $b->execute('DROP TABLE `' . self::EVENTS . '`');
        $b->execute('DROP TABLE `' . self::STATE . '`');
    }
}
