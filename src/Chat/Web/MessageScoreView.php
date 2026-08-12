<?php

declare(strict_types=1);

namespace App\Chat\Web;

use App\Chat\Domain\ChatAnswerScore;
use App\Chat\Domain\ChatAnswerScoreRepositoryInterface;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Message;

/**
 * Per-answer feedback state for one rendered thread, resolved once for the whole page.
 *
 * The same shape as {@see MessageEditView}: the decision lives here rather than in the template or the
 * browser, and the template only asks about a message id. The point of resolving it in one place is the
 * query — a thread renders up to 40 messages, so asking per message would be an N+1; this loads every
 * displayed answer's state in a single `IN (…)` pass.
 */
final readonly class MessageScoreView
{
    /**
     * @param array<int, ChatAnswerScore> $states Keyed by message id; an unrated answer is simply absent.
     */
    private function __construct(
        private array $states,
    ) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param list<Message> $messages The thread window being rendered, questions included — only the active
     *                                answers in it are looked up.
     */
    public static function compute(
        ChatAnswerScoreRepositoryInterface $scores,
        array $messages,
        ChatParticipant $participant,
    ): self {
        $answerIds = [];
        foreach ($messages as $message) {
            // Superseded answers never reach a live thread read, but the guard keeps this honest if one ever
            // does: a superseded answer must not offer a scoring control.
            if ($message->isAssistant() && !$message->isSuperseded()) {
                $answerIds[] = $message->id;
            }
        }

        if ($answerIds === []) {
            return self::none();
        }

        return new self($scores->findForMessages($answerIds, $participant));
    }

    /**
     * This participant's feedback on an answer, or null when they have neither rated nor dismissed it.
     */
    public function stateFor(int $messageId): ?ChatAnswerScore
    {
        return $this->states[$messageId] ?? null;
    }
}
