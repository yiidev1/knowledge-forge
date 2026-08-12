<?php

declare(strict_types=1);

namespace App\Chat\Infrastructure;

use App\Chat\Domain\ChatAnswerScore;
use App\Chat\Domain\ChatAnswerScoreRepositoryInterface;
use App\Chat\Domain\ChatParticipant;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\QueryInterface;

use function is_array;

/**
 * MySQL-backed answer feedback.
 *
 * Both writes are a single `INSERT … ON DUPLICATE KEY UPDATE` against
 * `ux_chat_answer_scores_msg_participant`, so re-scoring is one statement with no read-modify-write race:
 * two concurrent submits converge on one row rather than one of them failing on a duplicate key.
 */
final readonly class DbChatAnswerScoreRepository implements ChatAnswerScoreRepositoryInterface
{
    private const TABLE = '{{%chat_answer_scores}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function saveScore(int $messageId, ChatParticipant $participant, int $score, DateTimeImmutable $now): void
    {
        $timestamp = DbDateTime::format($now);

        // Scoring an answer that was previously dismissed clears the dismissal — the participant changed
        // their mind, and leaving it set would leave the row in two states at once.
        $this->connection->createCommand(
            'INSERT INTO ' . self::TABLE
            . ' ([[message_id]], [[participant_type]], [[participant_id]], [[score]], [[dismissed_at]],'
            . ' [[created_at]], [[updated_at]])'
            . ' VALUES (:messageId, :type, :participantId, :score, NULL, :now, :now)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' [[score]] = VALUES([[score]]), [[dismissed_at]] = NULL, [[updated_at]] = VALUES([[updated_at]])',
            [
                ':messageId' => $messageId,
                ':type' => $participant->type->value,
                ':participantId' => $participant->id,
                ':score' => $score,
                ':now' => $timestamp,
            ],
        )->execute();
    }

    public function saveDismissal(int $messageId, ChatParticipant $participant, DateTimeImmutable $now): void
    {
        $timestamp = DbDateTime::format($now);

        // Deliberately does NOT touch `score`. The service refuses a dismissal for an already-rated answer,
        // and this statement is written so that even if it were reached, an existing score would survive.
        $this->connection->createCommand(
            'INSERT INTO ' . self::TABLE
            . ' ([[message_id]], [[participant_type]], [[participant_id]], [[score]], [[dismissed_at]],'
            . ' [[created_at]], [[updated_at]])'
            . ' VALUES (:messageId, :type, :participantId, NULL, :now, :now, :now)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' [[dismissed_at]] = VALUES([[dismissed_at]]), [[updated_at]] = VALUES([[updated_at]])',
            [
                ':messageId' => $messageId,
                ':type' => $participant->type->value,
                ':participantId' => $participant->id,
                ':now' => $timestamp,
            ],
        )->execute();
    }

    public function findForMessage(int $messageId, ChatParticipant $participant): ?ChatAnswerScore
    {
        $row = $this->query()
            ->where([
                'message_id' => $messageId,
                'participant_type' => $participant->type->value,
                'participant_id' => $participant->id,
            ])
            ->limit(1)
            ->one();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findForMessages(array $messageIds, ChatParticipant $participant): array
    {
        if ($messageIds === []) {
            return [];
        }

        $rows = $this->query()
            ->where([
                'message_id' => $messageIds,
                'participant_type' => $participant->type->value,
                'participant_id' => $participant->id,
            ])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $score = $this->hydrate($row);
                $result[$score->messageId] = $score;
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): ChatAnswerScore
    {
        return new ChatAnswerScore(
            messageId: (int) $row['message_id'],
            score: $row['score'] === null ? null : (int) $row['score'],
            dismissedAt: DbDateTime::parseNullable(
                $row['dismissed_at'] === null ? null : (string) $row['dismissed_at'],
            ),
        );
    }

    private function query(): QueryInterface
    {
        return $this->connection->createQuery()->from(self::TABLE);
    }
}
