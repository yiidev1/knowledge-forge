<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * An optional short note explaining a low answer score.
 *
 * A column on the existing score row rather than a comments table: there is at most one comment per
 * (answer, participant), which is exactly the grain `chat_answer_scores` already has, so a second table
 * would add a join and a second write path for no extra expressiveness.
 *
 * Only a red-band score (1–3) may carry one, and the CHECK enforces it at the storage layer as well as in
 * {@see \App\Chat\Application\ScoreChatAnswerService}: raising a score to 4 or above must not leave the old
 * complaint attached to a good rating, so the column is cleared on the way up rather than merely hidden.
 *
 * Nullable and additive — existing rows keep their scores untouched and nothing is backfilled.
 */
final class M260812120000AddAnswerScoreFeedbackComment implements RevertibleMigrationInterface
{
    private const TABLE = 'chat_answer_scores';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`'
            . ' ADD COLUMN `feedback_comment` VARCHAR(500) NULL AFTER `score`,'
            . ' ADD CONSTRAINT `chk_chat_answer_scores_comment_low_only`'
            . '   CHECK (`feedback_comment` IS NULL OR (`score` IS NOT NULL AND `score` <= 3))',
        );
    }

    public function down(MigrationBuilder $b): void
    {
        // Additive and reconstructible from nothing else — but the comments themselves are first-party
        // feedback, so dropping the column loses them. The scores survive either way; only the notes go.
        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`'
            . ' DROP CONSTRAINT `chk_chat_answer_scores_comment_low_only`,'
            . ' DROP COLUMN `feedback_comment`',
        );
    }
}
