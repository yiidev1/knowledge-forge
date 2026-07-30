<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use DateTimeImmutable;

/**
 * A chat thread against one knowledge base. The title is derived from the opening question so the
 * conversation list is scannable; `lastMessageAt` orders that list by recent activity.
 */
final readonly class Conversation
{
    public function __construct(
        public int $id,
        public int $knowledgeBaseId,
        public string $title,
        public ?DateTimeImmutable $lastMessageAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
