<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use DateTimeImmutable;

/**
 * Persistence for conversations. Lookups are scoped by knowledge base and typed participant.
 */
interface ConversationRepositoryInterface
{
    /**
     * Canonical thread for a knowledge base + participant, or null if none exists yet.
     */
    public function findThread(int $knowledgeBaseId, ChatParticipant $participant): ?Conversation;

    /**
     * Inserts a thread row. Relies on ux_conversations_kb_participant_typed; callers must handle
     * IntegrityException by re-selecting.
     */
    public function createThread(
        int $knowledgeBaseId,
        ChatParticipant $participant,
        string $title,
        DateTimeImmutable $now,
    ): int;

    /**
     * Conversation id must belong to this KB and the given participant.
     */
    public function findOwnedThreadById(
        int $conversationId,
        int $knowledgeBaseId,
        ChatParticipant $participant,
    ): ?Conversation;

    /**
     * Records that the conversation just saw activity.
     */
    public function touch(int $conversationId, DateTimeImmutable $now): void;
}
