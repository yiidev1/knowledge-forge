<?php

declare(strict_types=1);

namespace App\Chat\Application;

use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ChatRetrievalScope;
use App\Chat\Domain\ChatSourceItem;
use App\Chat\Domain\ConversationRepositoryInterface;
use App\Chat\Domain\Exception\ConversationNotFound;
use App\Chat\Domain\Message;
use App\Chat\Domain\Exception\SourceNotVisible;
use App\Chat\Domain\MessageRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBase;

/**
 * Reads one cited source so a chat can show it inline, and decides whether the reader may see it at all.
 *
 * THIS CLASS IS THE SECURITY BOUNDARY. The chip in the template is a convenience, not a control: the
 * document id arrives from the browser and is treated as hostile. Every check below runs on every request,
 * in this order, and every failure raises the same not-found error so the response cannot be used to probe
 * for the existence of a store, a thread, a message or a document:
 *
 *   1. the conversation belongs to THIS knowledge base and THIS typed participant
 *      ({@see ConversationRepositoryInterface::findOwnedThreadById()} — the admin/agent realm split is
 *      carried by {@see ChatParticipant}, so an agent id can never open an admin thread and vice versa);
 *   2. the message belongs to that conversation;
 *   3. the message is an assistant answer, not a question;
 *   4. the answer has NOT been superseded by an edit — a superseded answer is hidden from the thread, so its
 *      sources must be unreachable too, not merely unlinked;
 *   5. the requested document is present in THAT answer's own resolved citations. This is what makes a
 *      hand-edited id useless: a document that exists, sits in the same knowledge base and reads perfectly
 *      is still refused unless this particular answer cited it;
 *   6. the document passes the surface's {@see ChatRetrievalScope} and the Store Profile visibility policy
 *      ({@see ChatKnowledgeSourcesService::detailFor()}, which is the transparency page's own filter).
 *
 * Steps 1–4 are deliberately identical to {@see ScoreChatAnswerService::resolveScorableAnswer()}; feedback
 * on an answer and the sources of an answer must not disagree about who may reach it.
 *
 * Read-only: nothing here writes, and nothing here influences retrieval, grounding or citation resolution.
 */
final readonly class ShowChatSourceService
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private MessageRepositoryInterface $messages,
        private ChatKnowledgeSourcesService $sources,
    ) {}

    /**
     * @throws \App\Shared\Domain\Exception\NotFoundException when the reader may not see this source.
     */
    public function detailFor(
        KnowledgeBase $knowledgeBase,
        ChatParticipant $participant,
        int $conversationId,
        int $messageId,
        int $documentId,
        ChatRetrievalScope $scope,
    ): ChatSourceItem {
        $answer = $this->resolveCitingAnswer($knowledgeBase, $participant, $conversationId, $messageId);

        if (!$this->cites($answer, $documentId)) {
            throw SourceNotVisible::notCitedByAnswer($documentId, $messageId);
        }

        $item = $this->sources->detailFor($knowledgeBase, $documentId, $scope);
        if ($item === null) {
            throw SourceNotVisible::notCitedByAnswer($documentId, $messageId);
        }

        return $item;
    }

    /**
     * Steps 1–4. Same lookups and same 404 semantics as scoring and editing, so a reader can only ever reach
     * a thread and a message they could already open.
     */
    private function resolveCitingAnswer(
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

        $answer = $this->messages->findByIdInConversation($messageId, $conversationId);
        if ($answer === null || !$answer->isAssistant() || $answer->isSuperseded()) {
            throw SourceNotVisible::notAVisibleAnswer($messageId);
        }

        return $answer;
    }

    /**
     * The citation gate. Matched on the resolved document id the server itself persisted with the answer —
     * never on a filename or a provider file id, both of which the client can see.
     */
    private function cites(Message $answer, int $documentId): bool
    {
        foreach ($answer->citations as $citation) {
            if ($citation->documentId === $documentId) {
                return true;
            }
        }

        return false;
    }
}
