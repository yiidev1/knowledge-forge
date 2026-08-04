<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure;

use App\Agent\Domain\AgentConversationRepositoryInterface;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Conversation;
use App\Chat\Domain\ConversationRepositoryInterface;
use DateTimeImmutable;

/**
 * Agent-facing facade over typed {@see ConversationRepositoryInterface}.
 */
final readonly class DbAgentConversationRepository implements AgentConversationRepositoryInterface
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
    ) {}

    public function create(int $knowledgeBaseId, int $agentAdminId, string $title, DateTimeImmutable $now): int
    {
        return $this->conversations->createThread(
            $knowledgeBaseId,
            ChatParticipant::agent($agentAdminId),
            $title,
            $now,
        );
    }

    public function findThread(int $knowledgeBaseId, int $agentAdminId): ?Conversation
    {
        return $this->conversations->findThread($knowledgeBaseId, ChatParticipant::agent($agentAdminId));
    }

    public function findForAgent(int $conversationId, int $knowledgeBaseId, int $agentAdminId): ?Conversation
    {
        return $this->conversations->findOwnedThreadById(
            $conversationId,
            $knowledgeBaseId,
            ChatParticipant::agent($agentAdminId),
        );
    }
}
