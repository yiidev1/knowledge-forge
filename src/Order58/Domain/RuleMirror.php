<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use DateTimeImmutable;

/**
 * A mirrored Order58 rule record. The stable source id is `sourceId` (= `rules.id`). `active` is a local soft
 * flag set to false when the source record disappears from a completed sync (mark-and-sweep) — the raw row is
 * never physically deleted. `sourceStoreId` is null until the Rules API provides an authoritative store id.
 */
final readonly class RuleMirror
{
    /**
     * @param array<array-key, mixed> $snapshot
     */
    public function __construct(
        public ?int $id,
        public int $sourceId,
        public ?string $type,
        public string $title,
        public string $description,
        public ?string $ruleKeyword,
        public ?string $createdName,
        public ?int $sourceStoreId,
        public bool $active,
        public string $syncHash,
        public ?DateTimeImmutable $sourceCreatedAt,
        public ?DateTimeImmutable $sourceUpdatedAt,
        public array $snapshot,
        public ?DateTimeImmutable $syncedAt = null,
    ) {}
}
