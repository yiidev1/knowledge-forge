<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * One entry of a canonical rule's classification history (append-only audit).
 */
final readonly class RuleEventRow
{
    public function __construct(
        public string $eventType,
        public ?string $oldStatus,
        public ?string $newStatus,
        public ?string $message,
        public ?int $adminUserId,
        public string $createdAt,
    ) {}
}
