<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use DateTimeImmutable;

/**
 * Persistence for messages within a conversation.
 */
interface MessageRepositoryInterface
{
    public function add(NewMessage $message, DateTimeImmutable $now): int;

    /**
     * @return list<Message> Oldest first — created_at ASC, id ASC.
     */
    public function findByConversation(int $conversationId): array;

    /**
     * Newest $limit messages, returned oldest-first for chronological display.
     *
     * @return list<Message>
     */
    public function findRecentByConversation(int $conversationId, int $limit): array;

    /**
     * Messages strictly before the cursor message, using a (created_at, id) tuple.
     * Returns up to $limit rows in created_at ASC, id ASC. Empty if the cursor is missing
     * or does not belong to the conversation (caller should 404).
     *
     * @return list<Message>|null null when the cursor message is not in this conversation
     */
    public function findBefore(int $conversationId, int $beforeMessageId, int $limit): ?array;

    public function countByConversation(int $conversationId): int;
}
