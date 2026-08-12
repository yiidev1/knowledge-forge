<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use DateTimeImmutable;

/**
 * Persistence for per-participant answer feedback.
 *
 * Every method is scoped by {@see ChatParticipant}: a score is never addressed by id alone, which is what
 * stops one participant's row being read or overwritten through another's request. Authorization that the
 * participant may reach the *message* at all happens a layer up, in
 * {@see \App\Chat\Application\ScoreChatAnswerService}.
 */
interface ChatAnswerScoreRepositoryInterface
{
    /**
     * Records a score, replacing any previous score by this participant for this answer and clearing a
     * prior dismissal — rating an answer you had declined is a normal transition.
     *
     * Idempotent by the (message_id, participant_type, participant_id) unique key, so a double submit
     * updates one row rather than inserting a second.
     *
     * @param int $score Already validated as an integer 1–10 by the caller.
     */
    public function saveScore(int $messageId, ChatParticipant $participant, int $score, DateTimeImmutable $now): void;

    /**
     * Records that this participant declined to rate this answer. Never writes a score — a dismissal has
     * no score, and this must not be reachable for an answer that already carries one.
     */
    public function saveDismissal(int $messageId, ChatParticipant $participant, DateTimeImmutable $now): void;

    /**
     * This participant's feedback on one answer, or null when they have neither rated nor dismissed it.
     */
    public function findForMessage(int $messageId, ChatParticipant $participant): ?ChatAnswerScore;

    /**
     * Feedback for a whole rendered page of answers in one query — the thread renders up to 40 messages, so
     * a per-message lookup would be an N+1.
     *
     * @param list<int> $messageIds
     *
     * @return array<int, ChatAnswerScore> Keyed by message id; ids with no feedback are simply absent.
     */
    public function findForMessages(array $messageIds, ChatParticipant $participant): array;
}
