<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Groups audio uploads into conversations, and ties them to a store.
 *
 * ## Why a parent table
 *
 * A Customer recording and an Agent recording uploaded together are one call. Without a parent, the
 * store history would count that as two conversions, the "one CUSTOMER and one AGENT" rule would have
 * nothing to live in, and an aggregate status would have nowhere to be derived from. Every upload gets
 * a parent — a common one has a single child — so the screens have one shape to render rather than two.
 *
 * ## Why the store is referenced without a foreign key
 *
 * `order58_stores` has no inbound foreign keys at all: `knowledge_bases`, `order58_knowledge_records`,
 * `order58_rule_records`, `order58_store_aliases` and `rule_store_links` all reference it by the
 * mirrored `source_id` with nothing enforcing it. `M260728120000CreateOrder58Mirrors` records the
 * reason — a row may arrive before its store has been synced. Audio follows the same convention rather
 * than becoming the first table to constrain a mirror that the sync rebuilds.
 *
 * Deletion is not a concern either way: the Order58 sync never deletes a store, it only deactivates
 * one it stopped seeing.
 *
 * ## Raw SQL
 *
 * Two CHECK constraints, which the fluent builder cannot express — the same reason
 * `M260831140000AddReviewedConversation` gives.
 */
final class M260902100000CreateAudioConversations implements RevertibleMigrationInterface
{
    private const CONVERSATIONS = 'audio_conversations';
    private const JOBS = 'audio_transcription_jobs';
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    /** Rows per back-fill pass, so a large table is never held in one statement's memory. */
    private const BATCH = 200;

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'CREATE TABLE `' . self::CONVERSATIONS . '` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `public_id` CHAR(32) NOT NULL,
                `store_source_id` BIGINT UNSIGNED NULL,
                `mode` VARCHAR(16) NOT NULL,
                `uploaded_by_admin_id` BIGINT NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_audio_conversations_public_id` (`public_id`),
                KEY `ix_audio_conversations_store` (`store_source_id`, `id`),
                CONSTRAINT `fk_audio_conversations_admin`
                    FOREIGN KEY (`uploaded_by_admin_id`) REFERENCES `admin_users` (`id`)
                    ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT `chk_audio_conversations_mode`
                    CHECK (`mode` IN (\'COMMON\', \'SEPARATE\'))
            ) ' . self::TABLE_OPTIONS,
        );

        // Both nullable, so adding them rewrites no existing row. The back-fill below fills them in.
        // `conversation_id` is BIGINT UNSIGNED to match the parent's key: a signed column here fails
        // at foreign-key creation with MySQL error 3780.
        $b->execute(
            'ALTER TABLE `' . self::JOBS . '`
                ADD COLUMN `conversation_id` BIGINT UNSIGNED NULL AFTER `id`,
                ADD COLUMN `source_role` VARCHAR(16) NULL AFTER `conversation_id`,
                ADD CONSTRAINT `fk_audio_transcription_jobs_conversation`
                    FOREIGN KEY (`conversation_id`) REFERENCES `' . self::CONVERSATIONS . '` (`id`)
                    ON DELETE RESTRICT ON UPDATE RESTRICT,
                ADD CONSTRAINT `chk_audio_transcription_jobs_source_role`
                    CHECK (`source_role` IS NULL OR `source_role` IN (\'COMMON\', \'CUSTOMER\', \'AGENT\'))',
        );

        $this->backfill($b);
    }

    /**
     * Give every pre-existing job a COMMON conversation of its own.
     *
     * These uploads predate stores, so `store_source_id` stays NULL — there is no store to infer, and
     * inventing one would be worse than recording that it is unknown.
     *
     * Only the two new columns are written. `transcript`, `speaker_segments`, `agent_text`,
     * `customer_text` and every `reviewed_*` column are neither read nor touched here.
     */
    private function backfill(MigrationBuilder $b): void
    {
        $db = $b->getDb();

        while (true) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $db->createCommand(
                'SELECT `id`, `uploaded_by_admin_id`, `created_at`
                 FROM `' . self::JOBS . '`
                 WHERE `conversation_id` IS NULL
                 ORDER BY `id` ASC
                 LIMIT ' . self::BATCH,
            )->queryAll();

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $jobId = (int) $row['id'];

                $db->createCommand(
                    'INSERT INTO `' . self::CONVERSATIONS . '`
                        (`public_id`, `store_source_id`, `mode`, `uploaded_by_admin_id`, `created_at`)
                     VALUES (:publicId, NULL, \'COMMON\', :adminId, :createdAt)',
                    [
                        // Its own identifier: the parent is addressable in its own right, and reusing
                        // the job's would make two different things answer to one id.
                        ':publicId' => bin2hex(random_bytes(16)),
                        ':adminId' => (int) $row['uploaded_by_admin_id'],
                        ':createdAt' => (string) $row['created_at'],
                    ],
                )->execute();

                $db->createCommand(
                    'UPDATE `' . self::JOBS . '`
                     SET `conversation_id` = :conversationId, `source_role` = \'COMMON\'
                     WHERE `id` = :id',
                    [':conversationId' => (int) $db->getLastInsertID(), ':id' => $jobId],
                )->execute();
            }
        }

        $orphans = (int) $db->createCommand(
            'SELECT COUNT(*) FROM `' . self::JOBS . '` WHERE `conversation_id` IS NULL',
        )->queryScalar();

        if ($orphans > 0) {
            throw new RuntimeException(
                'migrate:up aborted: ' . $orphans . ' job(s) still have no conversation after the '
                . 'back-fill. No job may be left without a parent.',
            );
        }
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * A conversation that names a store carries the only record of which store an upload belonged to;
     * the jobs themselves never held it. Dropping the table would lose that, so this stops instead and
     * says how much is at stake.
     */
    public function down(MigrationBuilder $b): void
    {
        $withStore = (int) $b->getDb()->createCommand(
            'SELECT COUNT(*) FROM `' . self::CONVERSATIONS . '` WHERE `store_source_id` IS NOT NULL',
        )->queryScalar();

        if ($withStore > 0) {
            throw new RuntimeException(
                'migrate:down aborted: ' . self::CONVERSATIONS . ' holds ' . $withStore
                . ' upload(s) tied to a store, and that association exists nowhere else. Export it, '
                . 'or clear the store-wise uploads first.',
            );
        }

        // The foreign key before the column it is defined on, and the parent table last.
        $b->execute(
            'ALTER TABLE `' . self::JOBS . '`
                DROP CHECK `chk_audio_transcription_jobs_source_role`,
                DROP FOREIGN KEY `fk_audio_transcription_jobs_conversation`',
        );
        $b->execute(
            'ALTER TABLE `' . self::JOBS . '`
                DROP COLUMN `source_role`,
                DROP COLUMN `conversation_id`',
        );
        $b->execute('DROP TABLE IF EXISTS `' . self::CONVERSATIONS . '`');
    }
}
