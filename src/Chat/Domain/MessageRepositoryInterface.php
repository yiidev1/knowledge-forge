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
     * @return list<Message> Oldest first — the order the thread is rendered in.
     */
    public function findByConversation(int $conversationId): array;

    /**
     * The newest $limit messages of a conversation, returned oldest-first for chronological display.
     *
     * The limit is applied in the database (newest-first + LIMIT), not by loading everything and slicing
     * in PHP, so a long conversation never pulls its whole history into memory just to show the tail.
     *
     * @return list<Message> Up to $limit messages, oldest of the selected window first.
     */
    public function findRecentByConversation(int $conversationId, int $limit): array;
}
