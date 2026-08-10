<?php

declare(strict_types=1);

namespace App\Chat\Application;

use App\Ai\Contract\Exception\AiException;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ChatRetrievalScope;
use App\Chat\Domain\ConversationRepositoryInterface;
use App\Chat\Domain\Exception\ConversationNotFound;
use App\Chat\Domain\Exception\EditConflict;
use App\Chat\Domain\Exception\MessageNotEditable;
use App\Chat\Domain\Exception\MessageNotFound;
use App\Chat\Domain\Exception\NotLatestQuestion;
use App\Chat\Domain\Exception\UnchangedContent;
use App\Chat\Domain\Message;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Domain\MessageRevisionRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Domain\Exception\NotFoundException;

/**
 * Edits the latest question in a chat thread and regenerates its answer.
 *
 * The rules are deliberately narrow so exactly one answer is ever invalidated: only the newest user
 * question is editable (at any age), and only by the participant who owns the thread. An edit runs in two
 * phases. First a short DB transaction commits the durable facts — the new question text (under an
 * optimistic lock on edit_count), an audit revision of the prior text, and the superseding of the old
 * answer. Then, outside the transaction, the assistant answer is regenerated synchronously. If the
 * provider fails, the committed edit is intentionally left in place with no active answer: a recoverable
 * "Retry" state, reported via {@see MessageEditOutcome} rather than an exception, so the question is never
 * lost and no duplicate active answer can appear.
 *
 * Ownership and the edit target are always resolved server-side from the typed participant and scoped
 * lookups; a forged conversation or message id is indistinguishable from a missing one (404). This service
 * powers both the admin and the agent surfaces — they differ only in how the participant is resolved.
 */
final readonly class EditChatMessageService
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private MessageRepositoryInterface $messages,
        private MessageRevisionRepositoryInterface $revisions,
        private AskKnowledgeBaseService $ask,
        private TransactionRunnerInterface $transaction,
        private ClockInterface $clock,
    ) {}

    /**
     * Edits the latest question and regenerates its answer.
     *
     * @throws NotFoundException when ownership or the target does not resolve (404)
     * @throws NotLatestQuestion|EditConflict|UnchangedContent recoverable, surfaced as a flash
     * @throws \App\Chat\Domain\Exception\QuestionInvalid empty or oversized content
     * @throws \App\Chat\Domain\Exception\ChatUnavailable the base cannot answer
     */
    public function edit(
        KnowledgeBase $knowledgeBase,
        ChatParticipant $participant,
        int $conversationId,
        int $messageId,
        string $newContent,
        int $expectedEditCount,
        ChatRetrievalScope $scope = ChatRetrievalScope::StoreKnowledge,
    ): MessageEditOutcome {
        $this->ask->assertChatAvailable($knowledgeBase, $scope);
        $target = $this->resolveLatestEditableTarget($knowledgeBase, $participant, $conversationId, $messageId);

        $prompt = $this->ask->validateQuestion($newContent);
        if ($prompt === $target->content) {
            throw UnchangedContent::create();
        }

        $priorContent = $target->content;
        $revisionNumber = $expectedEditCount + 1;
        $now = $this->clock->now();

        // Durable facts commit together; regeneration is deliberately outside this boundary.
        $this->transaction->run(function () use (
            $messageId,
            $conversationId,
            $expectedEditCount,
            $prompt,
            $revisionNumber,
            $priorContent,
            $participant,
            $now,
        ): void {
            if (!$this->messages->updateUserContent($messageId, $conversationId, $expectedEditCount, $prompt, $now)) {
                throw EditConflict::staleEditCount();
            }
            $this->revisions->add($messageId, $revisionNumber, $priorContent, $participant, $now);
            $this->messages->supersedeAnswersFor($messageId, $now);
        });

        return $this->regenerate($knowledgeBase, $conversationId, $messageId, $prompt, $scope);
    }

    /**
     * Retries a failed regeneration of the latest question's answer. Idempotent: if an active answer already
     * exists it is a no-op.
     *
     * @throws NotFoundException when ownership or the target does not resolve (404)
     * @throws NotLatestQuestion the target is no longer the latest question
     * @throws \App\Chat\Domain\Exception\ChatUnavailable the base cannot answer
     */
    public function regenerateAnswer(
        KnowledgeBase $knowledgeBase,
        ChatParticipant $participant,
        int $conversationId,
        int $messageId,
        ChatRetrievalScope $scope = ChatRetrievalScope::StoreKnowledge,
    ): MessageEditOutcome {
        $this->ask->assertChatAvailable($knowledgeBase, $scope);
        $target = $this->resolveLatestEditableTarget($knowledgeBase, $participant, $conversationId, $messageId);

        if (!$this->messages->hasUnansweredLatestUserMessage($conversationId)) {
            return MessageEditOutcome::regenerated();
        }

        return $this->regenerate($knowledgeBase, $conversationId, $messageId, $target->content, $scope);
    }

    /**
     * Resolves the edit/regenerate target with all server-side ownership and eligibility checks.
     */
    private function resolveLatestEditableTarget(
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
        if (!$target->isUser()) {
            throw MessageNotEditable::notAQuestion($messageId);
        }
        if ($target->id !== $this->messages->findLatestUserMessageId($conversationId)) {
            throw NotLatestQuestion::create();
        }

        return $target;
    }

    private function regenerate(
        KnowledgeBase $knowledgeBase,
        int $conversationId,
        int $messageId,
        string $prompt,
        ChatRetrievalScope $scope,
    ): MessageEditOutcome {
        try {
            $this->ask->regenerateForEditedMessage($knowledgeBase, $conversationId, $messageId, $prompt, $scope);

            return MessageEditOutcome::regenerated();
        } catch (AiException) {
            // The edit already committed. No active answer now means a recoverable Retry state — not a
            // failure to roll back, which would silently lose the user's edited question.
            return MessageEditOutcome::regenerationFailed();
        }
    }
}
