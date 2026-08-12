<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Chat;

use App\Chat\Domain\ChatAnswerScore;
use App\Chat\Domain\ChatAnswerScoreRepositoryInterface;
use App\Chat\Domain\ChatParticipant;
use DateTimeImmutable;

use function in_array;

/**
 * In-memory answer feedback for unit tests.
 *
 * Keyed exactly like the real unique index — (message, participant type, participant id) — so "saving twice
 * updates one row" and "one participant cannot see another's score" are properties of the fake too, not
 * things a test has to remember to assert around.
 */
final class InMemoryChatAnswerScoreRepository implements ChatAnswerScoreRepositoryInterface
{
    /** @var array<string, ChatAnswerScore> */
    private array $rows = [];

    /** @var list<string> Every write, in order — lets a test prove an update did not insert a second row. */
    public array $writes = [];

    public function saveScore(int $messageId, ChatParticipant $participant, int $score, DateTimeImmutable $now): void
    {
        // Scoring clears a prior dismissal, exactly like the ON DUPLICATE KEY UPDATE in the real repository.
        $this->rows[$this->key($messageId, $participant)] = new ChatAnswerScore($messageId, $score, null);
        $this->writes[] = 'score:' . $this->key($messageId, $participant);
    }

    public function saveDismissal(int $messageId, ChatParticipant $participant, DateTimeImmutable $now): void
    {
        $key = $this->key($messageId, $participant);
        $existing = $this->rows[$key] ?? null;

        // Never touches an existing score — the real statement only updates dismissed_at.
        $this->rows[$key] = new ChatAnswerScore($messageId, $existing?->score, $now);
        $this->writes[] = 'dismiss:' . $key;
    }

    public function findForMessage(int $messageId, ChatParticipant $participant): ?ChatAnswerScore
    {
        return $this->rows[$this->key($messageId, $participant)] ?? null;
    }

    public function findForMessages(array $messageIds, ChatParticipant $participant): array
    {
        $result = [];
        foreach ($this->rows as $row) {
            if (in_array($row->messageId, $messageIds, true)
                && isset($this->rows[$this->key($row->messageId, $participant)])) {
                $result[$row->messageId] = $this->rows[$this->key($row->messageId, $participant)];
            }
        }

        return $result;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    private function key(int $messageId, ChatParticipant $participant): string
    {
        return $messageId . ':' . $participant->type->value . ':' . $participant->id;
    }
}
