<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Chat;

use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Conversation;
use App\Chat\Domain\ConversationRepositoryInterface;
use App\Chat\Domain\ParticipantType;
use DateTimeImmutable;
use Yiisoft\Db\Exception\IntegrityException;

/**
 * In-memory conversation repository keyed by (kb, type, participant id).
 */
final class InMemoryConversationRepository implements ConversationRepositoryInterface
{
    /** @var array<int, array{conversation: Conversation, participant: ChatParticipant}> */
    private array $items = [];

    private int $nextId = 1;

    public function findThread(int $knowledgeBaseId, ChatParticipant $participant): ?Conversation
    {
        foreach ($this->items as $row) {
            if (
                $row['conversation']->knowledgeBaseId === $knowledgeBaseId
                && $row['participant']->type === $participant->type
                && $row['participant']->id === $participant->id
            ) {
                return $row['conversation'];
            }
        }

        return null;
    }

    public function createThread(
        int $knowledgeBaseId,
        ChatParticipant $participant,
        string $title,
        DateTimeImmutable $now,
    ): int {
        if ($this->findThread($knowledgeBaseId, $participant) !== null) {
            throw new IntegrityException('Duplicate conversation for participant.');
        }

        $id = $this->nextId++;
        $this->items[$id] = [
            'conversation' => new Conversation($id, $knowledgeBaseId, $title, $now, $now, $now),
            'participant' => $participant,
        ];

        return $id;
    }

    public function findOwnedThreadById(
        int $conversationId,
        int $knowledgeBaseId,
        ChatParticipant $participant,
    ): ?Conversation {
        $row = $this->items[$conversationId] ?? null;
        if ($row === null) {
            return null;
        }
        if (
            $row['conversation']->knowledgeBaseId !== $knowledgeBaseId
            || $row['participant']->type !== $participant->type
            || $row['participant']->id !== $participant->id
        ) {
            return null;
        }

        return $row['conversation'];
    }

    public function touch(int $conversationId, DateTimeImmutable $now): void
    {
        $row = $this->items[$conversationId] ?? null;
        if ($row === null) {
            return;
        }

        $existing = $row['conversation'];
        $this->items[$conversationId] = [
            'conversation' => new Conversation(
                $existing->id,
                $existing->knowledgeBaseId,
                $existing->title,
                $now,
                $existing->createdAt,
                $now,
            ),
            'participant' => $row['participant'],
        ];
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function countForType(ParticipantType $type): int
    {
        $n = 0;
        foreach ($this->items as $row) {
            if ($row['participant']->type === $type) {
                ++$n;
            }
        }

        return $n;
    }
}
