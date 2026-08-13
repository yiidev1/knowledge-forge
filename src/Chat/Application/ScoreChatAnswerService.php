<?php

declare(strict_types=1);

namespace App\Chat\Application;

use App\Chat\Domain\ChatAnswerScoreRepositoryInterface;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ConversationRepositoryInterface;
use App\Chat\Domain\Exception\AnswerScoreInvalid;
use App\Chat\Domain\Exception\ConversationNotFound;
use App\Chat\Domain\Exception\MessageNotFound;
use App\Chat\Domain\Exception\MessageNotScorable;
use App\Chat\Domain\Message;
use App\Chat\Domain\MessageRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\Shared\Domain\Clock\ClockInterface;

use function is_int;
use function is_string;
use function mb_strlen;
use function preg_match;
use function trim;

/**
 * Records a participant's 1–10 rating of an assistant answer, or their decision not to rate it.
 *
 * Feedback only: it makes no provider call, reads no retrieval state and cannot alter an answer. It is
 * deliberately independent of {@see ChatAvailabilityPolicy} — a store whose chat has since become
 * unavailable must still let a reader rate the answers it already produced, otherwise feedback would
 * silently disappear exactly when it is most interesting.
 *
 * Authorization mirrors {@see EditChatMessageService::resolveLatestEditableTarget()} and reuses the same two
 * scoped lookups, so a score cannot reach a thread or a message the participant could not already open.
 */
final readonly class ScoreChatAnswerService
{
    private const MIN_SCORE = 1;
    private const MAX_SCORE = 10;

    /**
     * The top of the red band. Only a score at or below this may carry a note explaining it — above it the
     * note is dropped, so a rating that no longer criticises the answer cannot keep criticism attached.
     */
    private const MAX_COMMENTABLE_SCORE = 3;

    /** Matches the column width; a note is a sentence or two, not an essay. */
    private const MAX_COMMENT_LENGTH = 500;

    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private MessageRepositoryInterface $messages,
        private ChatAnswerScoreRepositoryInterface $scores,
        private ClockInterface $clock,
    ) {}

    /**
     * @param mixed $rawScore Straight from the request body — validated here, never coerced.
     *
     * @throws \App\Shared\Domain\Exception\NotFoundException when the target is not this participant's answer.
     * @throws AnswerScoreInvalid when the score is not an integer 1–10.
     */
    public function score(
        KnowledgeBase $knowledgeBase,
        ChatParticipant $participant,
        int $conversationId,
        int $messageId,
        mixed $rawScore,
        mixed $rawComment = null,
    ): void {
        $answer = $this->resolveScorableAnswer($knowledgeBase, $participant, $conversationId, $messageId);
        $score = $this->parseScore($rawScore);

        $this->scores->saveScore($answer->id, $participant, $score, $this->parseComment($score, $rawComment), $this->clock->now());
    }

    /**
     * A note is optional, kept only for a red-band score, and never trusted from the browser.
     *
     * Above the red band it is discarded rather than rejected: the user may have typed a complaint, moved
     * the slider up, and saved — the higher score is what they meant, and keeping the complaint would
     * attach criticism to a rating that no longer makes it. The database CHECK enforces the same rule.
     *
     * @throws AnswerScoreInvalid when the note is longer than the column allows.
     */
    private function parseComment(int $score, mixed $rawComment): ?string
    {
        if ($score > self::MAX_COMMENTABLE_SCORE) {
            return null;
        }

        if (!is_string($rawComment)) {
            return null;
        }

        $comment = trim($rawComment);
        if ($comment === '') {
            return null;
        }

        if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw AnswerScoreInvalid::commentTooLong(self::MAX_COMMENT_LENGTH);
        }

        return $comment;
    }

    /**
     * @throws \App\Shared\Domain\Exception\NotFoundException when the target is not this participant's answer.
     * @throws AnswerScoreInvalid when the answer already carries a score.
     */
    public function dismiss(
        KnowledgeBase $knowledgeBase,
        ChatParticipant $participant,
        int $conversationId,
        int $messageId,
    ): void {
        $answer = $this->resolveScorableAnswer($knowledgeBase, $participant, $conversationId, $messageId);

        // A rated answer only offers "Change", so a dismissal here is a stale page or a crafted request.
        // Refuse it rather than let it discard a score the participant deliberately gave.
        $existing = $this->scores->findForMessage($answer->id, $participant);
        if ($existing !== null && $existing->isRated()) {
            throw AnswerScoreInvalid::alreadyRated();
        }

        $this->scores->saveDismissal($answer->id, $participant, $this->clock->now());
    }

    /**
     * Strict: only a value that *is* an integer 1–10, or a string of digits denoting one, is accepted.
     * `"8abc"`, `"8.5"`, `""`, `0` and `11` are all rejected — a plain `(int)` cast would silently turn the
     * first two into 8, recording a score the participant never chose.
     */
    private function parseScore(mixed $rawScore): int
    {
        if (is_int($rawScore)) {
            $score = $rawScore;
        } elseif (is_string($rawScore) && preg_match('/^\d+$/', trim($rawScore)) === 1) {
            $score = (int) trim($rawScore);
        } else {
            throw AnswerScoreInvalid::outOfRange();
        }

        if ($score < self::MIN_SCORE || $score > self::MAX_SCORE) {
            throw AnswerScoreInvalid::outOfRange();
        }

        return $score;
    }

    /**
     * The four scoped checks, in the same order and with the same 404 semantics as editing:
     * the thread belongs to this knowledge base AND this typed participant; the message belongs to that
     * thread; it is an answer, not a question; and it has not been superseded by an edit.
     */
    private function resolveScorableAnswer(
        KnowledgeBase $knowledgeBase,
        ChatParticipant $participant,
        int $conversationId,
        int $messageId,
    ): Message {
        $conversation = $this->conversations->findOwnedThreadById(
            $conversationId,
            $knowledgeBase->id(),
            $participant,
        );
        if ($conversation === null) {
            throw ConversationNotFound::inKnowledgeBase($conversationId, $knowledgeBase->id());
        }

        $target = $this->messages->findByIdInConversation($messageId, $conversationId);
        if ($target === null) {
            throw MessageNotFound::inConversation($messageId, $conversationId);
        }

        if (!$target->isAssistant()) {
            throw MessageNotScorable::notAnAnswer($messageId);
        }

        if ($target->isSuperseded()) {
            throw MessageNotScorable::superseded($messageId);
        }

        return $target;
    }
}
