<?php

declare(strict_types=1);

namespace App\Chat\Web;

use App\Auth\Application\CurrentAdmin;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Conversation;
use App\Chat\Domain\ConversationRepositoryInterface;
use App\Chat\Domain\Exception\ConversationNotFound;

/**
 * Resolves a `{conversationId}` to the logged-in admin's thread for a knowledge base, or 404.
 */
final readonly class ConversationFinder
{
    public function __construct(
        private ConversationRepositoryInterface $repository,
        private CurrentAdmin $currentAdmin,
    ) {}

    public function forKnowledgeBase(int $conversationId, int $knowledgeBaseId): Conversation
    {
        $participant = ChatParticipant::admin($this->currentAdmin->get()->id());
        $conversation = $this->repository->findOwnedThreadById(
            $conversationId,
            $knowledgeBaseId,
            $participant,
        );

        if (!$conversation instanceof Conversation) {
            throw ConversationNotFound::inKnowledgeBase($conversationId, $knowledgeBaseId);
        }

        return $conversation;
    }
}
