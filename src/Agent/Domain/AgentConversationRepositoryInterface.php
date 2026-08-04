<?php

declare(strict_types=1);

namespace App\Agent\Domain;

use App\Chat\Domain\Conversation;
use DateTimeImmutable;

/**
 * Agent-scoped conversation persistence over the shared `conversations` table.
 */
interface AgentConversationRepositoryInterface
{
    public function create(int $knowledgeBaseId, int $agentAdminId, string $title, DateTimeImmutable $now): int;

    public function findThread(int $knowledgeBaseId, int $agentAdminId): ?Conversation;

    public function findForAgent(int $conversationId, int $knowledgeBaseId, int $agentAdminId): ?Conversation;
}
