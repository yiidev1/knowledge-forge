<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Chat;

use App\Chat\Domain\Message;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Domain\NewMessage;
use DateTimeImmutable;

use function array_reverse;
use function array_slice;
use function array_values;
use function usort;

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

        usort(
            $result,
            static fn(Message $a, Message $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id],
        );

        return array_values($result);
    }

    public function findRecentByConversation(int $conversationId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $all = $this->findByConversation($conversationId);

        return array_slice($all, -$limit);
    }

    public function findBefore(int $conversationId, int $beforeMessageId, int $limit): ?array
    {
        if ($limit < 1) {
            return [];
        }

        $cursor = $this->items[$beforeMessageId] ?? null;
        if ($cursor === null || $cursor->conversationId !== $conversationId) {
            return null;
        }

        $older = [];
        foreach ($this->findByConversation($conversationId) as $message) {
            if (
                $message->createdAt < $cursor->createdAt
                || ($message->createdAt == $cursor->createdAt && $message->id < $cursor->id)
            ) {
                $older[] = $message;
            }
        }

        return array_slice($older, -$limit);
    }

    public function countByConversation(int $conversationId): int
    {
        return count($this->findByConversation($conversationId));
    }
}
