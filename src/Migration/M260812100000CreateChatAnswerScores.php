<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Per-participant 1–10 feedback on an assistant answer, kept in its own table rather than as columns on
 * `messages`.
 *
 * A separate table is what keeps the answer immutable: `messages` rows are written by the chat pipeline and
 * superseded by edits, while a score is written later by a reader and must survive independently of that
 * lifecycle. It also keeps the grain right — a score belongs to (answer, participant), not to the answer —
 * so the same answer could be rated by an admin and an agent without either overwriting the other.
 *
 * `score` and `dismissed_at` are both nullable but never both null: a row exists only because a participant
 * either rated the answer or explicitly declined to (`chk_chat_answer_scores_meaningful`). "Declined" is
 * deliberately NOT score 0 — a dismissal carries no score at all, so it can never drag an average down.
 *
 * No `knowledge_base_id`/`conversation_id` is copied here. Every future report (average, distribution, by
 * admin vs agent, by store, grounded vs fallback, by date) joins `messages → conversations →
 * knowledge_bases`, which already carry `is_grounded`, `answer_source` and `created_at`; duplicating those
 * ids would create a second source of truth that could drift.
 *
 * The unique key is the only index: it enforces one row per (answer, participant) — so a re-score updates
 * rather than duplicates — and its leading `message_id` also serves the one query the UI runs, which loads
 * every displayed answer's state in a single `IN (…)` pass.
 */
final class M260812100000CreateChatAnswerScores implements RevertibleMigrationInterface
{
    private const TABLE = 'chat_answer_scores';
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(MigrationBuilder $b): void
    {
        // Raw SQL rather than the fluent builder: this table needs CHECK constraints and an inline foreign
        // key, neither of which MigrationBuilder can express — the same reason the rule-classification and
        // message-revision migrations use raw SQL.
        $b->execute(
            'CREATE TABLE `' . self::TABLE . '` ('
            . ' `id` BIGINT AUTO_INCREMENT PRIMARY KEY,'
            . ' `message_id` BIGINT NOT NULL,'
            . ' `participant_type` VARCHAR(16) NOT NULL,'
            . ' `participant_id` BIGINT UNSIGNED NOT NULL,'
            . ' `score` TINYINT UNSIGNED NULL,'
            . ' `dismissed_at` DATETIME NULL,'
            . ' `created_at` DATETIME NOT NULL,'
            . ' `updated_at` DATETIME NOT NULL,'
            . ' UNIQUE KEY `ux_chat_answer_scores_msg_participant`'
            . '   (`message_id`, `participant_type`, `participant_id`),'
            . ' CONSTRAINT `fk_chat_answer_scores_message` FOREIGN KEY (`message_id`)'
            . '   REFERENCES `messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,'
            . ' CONSTRAINT `chk_chat_answer_scores_participant_type`'
            . "   CHECK (`participant_type` IN ('admin','agent')),"
            . ' CONSTRAINT `chk_chat_answer_scores_participant_id_positive` CHECK (`participant_id` > 0),'
            // Defence in depth: the service validates the range, and the column refuses anything else.
            . ' CONSTRAINT `chk_chat_answer_scores_range`'
            . '   CHECK (`score` IS NULL OR (`score` BETWEEN 1 AND 10)),'
            . ' CONSTRAINT `chk_chat_answer_scores_meaningful`'
            . '   CHECK (`score` IS NOT NULL OR `dismissed_at` IS NOT NULL)'
            . ') ' . self::TABLE_OPTIONS,
        );
    }

    public function down(MigrationBuilder $b): void
    {
        // Scores are first-party feedback that exists nowhere else — unlike a projection, dropping them
        // loses them for good. Refuse rather than destroy, matching the rule-catalog migrations.
        $rows = (int) $b->getDb()->createCommand('SELECT COUNT(*) FROM `' . self::TABLE . '`')->queryScalar();
        if ($rows > 0) {
            throw new RuntimeException(
                'migrate:down aborted: ' . self::TABLE . ' holds answer feedback that cannot be reconstructed. '
                . 'Restore from the pre-migration mysqldump instead.',
            );
        }

        $b->execute('DROP TABLE `' . self::TABLE . '`');
    }
}
