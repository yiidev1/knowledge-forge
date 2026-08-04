<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Chat;

use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\MessageRevision;
use App\Chat\Domain\MessageRevisionRepositoryInterface;
use DateTimeImmutable;

use function array_values;
use function usort;

/**
 * In-memory audit trail of edited questions for unit tests.
 */
final class InMemoryMessageRevisionRepository implements MessageRevisionRepositoryInterface
{
    /** @var array<int, MessageRevision> */
    private array $items = [];

    private int $nextId = 1;

    public function add(
        int $messageId,
        int $revisionNumber,
        string $priorContent,
        ChatParticipant $editor,
        DateTimeImmutable $now,
    ): int {
        $id = $this->nextId++;
        $this->items[$id] = new MessageRevision(
            id: $id,
            messageId: $messageId,
            revisionNumber: $revisionNumber,
            content: $priorContent,
            editedByType: $editor->type,
            editedById: $editor->id,
            createdAt: $now,
        );

        return $id;
    }

    public function findByMessage(int $messageId): array
    {
        $result = [];
        foreach ($this->items as $revision) {
            if ($revision->messageId === $messageId) {
                $result[] = $revision;
            }
        }

        usort($result, static fn(MessageRevision $a, MessageRevision $b): int => $a->revisionNumber <=> $b->revisionNumber);

        return array_values($result);
    }
}
