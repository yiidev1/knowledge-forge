<?php

declare(strict_types=1);

namespace App\Agent\Domain;

use App\Chat\Domain\Conversation;
use DateTimeImmutable;

/**
 * Agent-scoped conversation persistence over the shared `conversations` table. Every read is scoped by
 * both knowledge base and owning agent, so a conversation id from another agent — or another store — can
 * never be opened by URL manipulation.
 */
interface AgentConversationRepositoryInterface
{
    public function create(int $knowledgeBaseId, int $agentAdminId, string $title, DateTimeImmutable $now): int;

    /**
     * @return list<Conversation> The agent's conversations in one store, newest activity first.
     */
    public function findForAgentInKnowledgeBase(int $knowledgeBaseId, int $agentAdminId): array;

    public function findForAgent(int $conversationId, int $knowledgeBaseId, int $agentAdminId): ?Conversation;
}
