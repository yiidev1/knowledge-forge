<?php

declare(strict_types=1);

namespace App\Order58\Contract\Dto;

use DateTimeImmutable;

/**
 * A knowledge record. The stable source id is `knowledge.id`; store ownership is `knowledge.store`, which
 * references `account.id`. The semantic body is `title` + `content` (`description`); `_sync_hash` drives
 * change detection and is deliberately excluded from the generated document body.
 */
final readonly class Order58KnowledgeRecord
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public int $id,
        public int $storeId,
        public string $title,
        public string $content,
        public ?string $knowledgeNumber,
        public ?string $keyword,
        public ?string $type,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public string $syncHash,
        public array $raw,
    ) {}
}
