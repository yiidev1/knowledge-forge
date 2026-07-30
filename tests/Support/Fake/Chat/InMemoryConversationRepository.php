<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Chat;

use App\Chat\Domain\Conversation;
use App\Chat\Domain\ConversationRepositoryInterface;
use DateTimeImmutable;

use function array_values;

/**
 * In-memory conversation repository for unit tests.
 */
final class InMemoryConversationRepository implements ConversationRepositoryInterface
{
    /** @var array<int, Conversation> */
    private array $items = [];

    private int $nextId = 1;

    public function create(int $knowledgeBaseId, string $title, DateTimeImmutable $now): int
    {
        $id = $this->nextId++;
        $this->items[$id] = new Conversation($id, $knowledgeBaseId, $title, $now, $now, $now);

        return $id;
    }

    public function findByIdForKnowledgeBase(int $conversationId, int $knowledgeBaseId): ?Conversation
    {
        $conversation = $this->items[$conversationId] ?? null;

        return $conversation !== null && $conversation->knowledgeBaseId === $knowledgeBaseId ? $conversation : null;
    }

    public function findAllForKnowledgeBase(int $knowledgeBaseId): array
    {
        $result = [];
        foreach ($this->items as $conversation) {
            if ($conversation->knowledgeBaseId === $knowledgeBaseId) {
                $result[] = $conversation;
            }
        }

        return array_values($result);
    }

    public function touch(int $conversationId, DateTimeImmutable $now): void
    {
        $existing = $this->items[$conversationId] ?? null;
        if ($existing === null) {
            return;
        }

        $this->items[$conversationId] = new Conversation(
            $existing->id,
            $existing->knowledgeBaseId,
            $existing->title,
            $now,
            $existing->createdAt,
            $now,
        );
    }

    public function count(): int
    {
        return count($this->items);
    }
}
