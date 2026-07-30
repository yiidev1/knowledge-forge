<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use DateTimeImmutable;

/**
 * Persistence for conversations. Every lookup is scoped by knowledge base, so a conversation id from one
 * base can never be opened under another.
 */
interface ConversationRepositoryInterface
{
    public function create(int $knowledgeBaseId, string $title, DateTimeImmutable $now): int;

    public function findByIdForKnowledgeBase(int $conversationId, int $knowledgeBaseId): ?Conversation;

    /**
     * @return list<Conversation> Newest activity first.
     */
    public function findAllForKnowledgeBase(int $knowledgeBaseId): array;

    /**
     * Records that the conversation just saw activity, so it sorts to the top of the list.
     */
    public function touch(int $conversationId, DateTimeImmutable $now): void;
}
