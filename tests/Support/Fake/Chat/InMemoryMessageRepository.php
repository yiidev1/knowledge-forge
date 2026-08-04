<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Chat;

use App\Chat\Domain\Message;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Domain\MessageRole;
use App\Chat\Domain\NewMessage;
use DateTimeImmutable;

use function array_slice;
use function array_values;
use function count;
use function usort;

/**
 * In-memory message repository for unit tests.
 *
 * Mirrors the DB repository's editing semantics: live/history reads exclude superseded answers, the
 * active-answer invariant is upheld by {@see insertActiveAnswer} (a second active answer for the same
 * question returns the first instead of duplicating), and {@see updateUserContent} enforces the optimistic
 * lock on edit_count. Messages are immutable, so "mutations" replace the stored value.
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
            replyToMessageId: $message->replyToMessageId,
        );

        return $id;
    }

    public function insertActiveAnswer(NewMessage $answer, DateTimeImmutable $now): int
    {
        if ($answer->replyToMessageId !== null) {
            $existing = $this->findActiveAnswerId($answer->replyToMessageId);
            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->add($answer, $now);
    }

    public function findByConversation(int $conversationId): array
    {
        return $this->sorted($conversationId, false);
    }

    public function findRecentByConversation(int $conversationId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        return array_slice($this->sorted($conversationId, false), -$limit);
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
        foreach ($this->sorted($conversationId, false) as $message) {
            if ($this->isBefore($message, $cursor)) {
                $older[] = $message;
            }
        }

        return array_slice($older, -$limit);
    }

    public function findActiveBefore(int $conversationId, int $messageId): array
    {
        $cursor = $this->items[$messageId] ?? null;
        if ($cursor === null || $cursor->conversationId !== $conversationId) {
            return [];
        }

        $older = [];
        foreach ($this->sorted($conversationId, false) as $message) {
            if ($this->isBefore($message, $cursor)) {
                $older[] = $message;
            }
        }

        return array_values($older);
    }

    public function countByConversation(int $conversationId): int
    {
        return count($this->sorted($conversationId, false));
    }

    public function findByIdInConversation(int $messageId, int $conversationId): ?Message
    {
        $message = $this->items[$messageId] ?? null;

        return $message !== null && $message->conversationId === $conversationId ? $message : null;
    }

    public function findLatestUserMessageId(int $conversationId): ?int
    {
        $users = [];
        foreach ($this->items as $message) {
            if ($message->conversationId === $conversationId && $message->isUser()) {
                $users[] = $message;
            }
        }
        if ($users === []) {
            return null;
        }

        usort($users, static fn(Message $a, Message $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);

        return $users[count($users) - 1]->id;
    }

    public function updateUserContent(
        int $messageId,
        int $conversationId,
        int $expectedEditCount,
        string $content,
        DateTimeImmutable $now,
    ): bool {
        $message = $this->items[$messageId] ?? null;
        if (
            $message === null
            || $message->conversationId !== $conversationId
            || !$message->isUser()
            || $message->editCount !== $expectedEditCount
        ) {
            return false;
        }

        $this->items[$messageId] = new Message(
            id: $message->id,
            conversationId: $message->conversationId,
            role: $message->role,
            content: $content,
            citations: $message->citations,
            isGrounded: $message->isGrounded,
            retrievalStatus: $message->retrievalStatus,
            model: $message->model,
            createdAt: $message->createdAt,
            replyToMessageId: $message->replyToMessageId,
            supersededAt: $message->supersededAt,
            editedAt: $now,
            editCount: $message->editCount + 1,
        );

        return true;
    }

    public function supersedeAnswersFor(int $userMessageId, DateTimeImmutable $now): void
    {
        foreach ($this->items as $id => $message) {
            if (
                $message->role === MessageRole::Assistant
                && $message->replyToMessageId === $userMessageId
                && $message->supersededAt === null
            ) {
                $this->items[$id] = new Message(
                    id: $message->id,
                    conversationId: $message->conversationId,
                    role: $message->role,
                    content: $message->content,
                    citations: $message->citations,
                    isGrounded: $message->isGrounded,
                    retrievalStatus: $message->retrievalStatus,
                    model: $message->model,
                    createdAt: $message->createdAt,
                    replyToMessageId: $message->replyToMessageId,
                    supersededAt: $now,
                    editedAt: $message->editedAt,
                    editCount: $message->editCount,
                );
            }
        }
    }

    public function hasUnansweredLatestUserMessage(int $conversationId): bool
    {
        $latestUserId = $this->findLatestUserMessageId($conversationId);
        if ($latestUserId === null) {
            return false;
        }

        return $this->findActiveAnswerId($latestUserId) === null;
    }

    public function findAllByConversationIncludingSuperseded(int $conversationId): array
    {
        return $this->sorted($conversationId, true);
    }

    private function findActiveAnswerId(int $userMessageId): ?int
    {
        foreach ($this->items as $message) {
            if (
                $message->role === MessageRole::Assistant
                && $message->replyToMessageId === $userMessageId
                && $message->supersededAt === null
            ) {
                return $message->id;
            }
        }

        return null;
    }

    /**
     * @return list<Message>
     */
    private function sorted(int $conversationId, bool $includeSuperseded): array
    {
        $result = [];
        foreach ($this->items as $message) {
            if ($message->conversationId !== $conversationId) {
                continue;
            }
            if (!$includeSuperseded && $message->supersededAt !== null) {
                continue;
            }
            $result[] = $message;
        }

        usort(
            $result,
            static fn(Message $a, Message $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id],
        );

        return array_values($result);
    }

    private function isBefore(Message $message, Message $cursor): bool
    {
        return $message->createdAt < $cursor->createdAt
            || ($message->createdAt == $cursor->createdAt && $message->id < $cursor->id);
    }
}
