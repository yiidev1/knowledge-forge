<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Chat;

use App\Chat\Domain\Message;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Domain\NewMessage;
use DateTimeImmutable;

use function array_slice;
use function array_values;

/**
 * In-memory message repository for unit tests.
 */
final class InMemoryMessageRepository implements MessageRepositoryInterface
{
    /** @var array<int, Message> */
    private array $items = [];

    private int $nextId = 1;

    public function add(NewMessage $message, DateTimeImmutable $now): int
    {
        $id = $this->nextId++;
        $this->items[$id] = new Message(
            id: $id,
            conversationId: $message->conversationId,
            role: $message->role,
            content: $message->content,
            citations: $message->citations,
            isGrounded: $message->isGrounded,
            retrievalStatus: $message->retrievalStatus,
            model: $message->model,
            createdAt: $now,
        );

        return $id;
    }

    public function findByConversation(int $conversationId): array
    {
        $result = [];
        foreach ($this->items as $message) {
            if ($message->conversationId === $conversationId) {
                $result[] = $message;
            }
        }

        return array_values($result);
    }

    public function findRecentByConversation(int $conversationId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        // Mirror the DB repository: take the newest $limit (by id), return them oldest-first.
        $all = $this->findByConversation($conversationId);

        return array_slice($all, -$limit);
    }
}
