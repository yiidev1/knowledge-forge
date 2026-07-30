<?php

declare(strict_types=1);

namespace App\Chat\Web;

use App\Chat\Domain\Conversation;
use App\Chat\Domain\ConversationRepositoryInterface;
use App\Chat\Domain\Exception\ConversationNotFound;

/**
 * Resolves a `{conversationId}` within a knowledge base to a conversation, or a 404. Written once so the
 * scoped "load or fail" step is identical across the chat actions.
 */
final readonly class ConversationFinder
{
    public function __construct(
        private ConversationRepositoryInterface $repository,
    ) {}

    public function forKnowledgeBase(int $conversationId, int $knowledgeBaseId): Conversation
    {
        $conversation = $this->repository->findByIdForKnowledgeBase($conversationId, $knowledgeBaseId);

        if (!$conversation instanceof Conversation) {
            throw ConversationNotFound::inKnowledgeBase($conversationId, $knowledgeBaseId);
        }

        return $conversation;
    }
}
