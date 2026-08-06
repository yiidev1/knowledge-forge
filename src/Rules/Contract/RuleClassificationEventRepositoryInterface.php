<?php

declare(strict_types=1);

namespace App\Rules\Contract;

use DateTimeImmutable;

/**
 * Append-only audit of canonical-rule classification changes. Rows are never updated or deleted.
 */
interface RuleClassificationEventRepositoryInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function record(
        int $canonicalId,
        string $eventType,
        ?string $oldStatus,
        ?string $newStatus,
        ?string $message,
        array $metadata,
        ?int $adminUserId,
        DateTimeImmutable $now,
    ): void;
}
