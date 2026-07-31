<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use DateTimeImmutable;

/**
 * A mirrored Order58 knowledge record. Store ownership is `storeSourceId` (= `knowledge.store`, which
 * references `account.id`). `active` is a local soft flag set to false when the source record disappears.
 */
final readonly class KnowledgeMirror
{
    /**
     * @param array<array-key, mixed> $snapshot
     */
    public function __construct(
        public ?int $id,
        public int $sourceId,
        public int $storeSourceId,
        public string $title,
        public string $content,
        public ?string $knowledgeNumber,
        public ?string $keyword,
        public ?string $type,
        public bool $active,
        public string $syncHash,
        public ?DateTimeImmutable $sourceCreatedAt,
        public ?DateTimeImmutable $sourceUpdatedAt,
        public array $snapshot,
        public ?DateTimeImmutable $syncedAt = null,
    ) {}
}
