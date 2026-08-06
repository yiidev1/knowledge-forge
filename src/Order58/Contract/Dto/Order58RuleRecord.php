<?php

declare(strict_types=1);

namespace App\Order58\Contract\Dto;

use DateTimeImmutable;

/**
 * An Order58 rule record from `GET /api/integration/v1/rules`. The stable source id is `rules.id`; the semantic
 * body is `title` + `description`. `_sync_hash` drives change detection and is deliberately excluded from the
 * curated snapshot downstream.
 *
 * The current Rules API carries no store id, so `sourceStoreId` is always null for now; it exists so a future
 * authoritative store id can flow through without redesign.
 */
final readonly class Order58RuleRecord
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public int $id,
        public ?string $type,
        public string $title,
        public string $description,
        public ?string $ruleKeyword,
        public ?string $createdName,
        public ?int $sourceStoreId,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public string $syncHash,
        public array $raw,
    ) {}
}
