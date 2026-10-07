<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

use DateTimeImmutable;

/**
 * One operator setting out to recreate one source order in Order58.
 *
 * Written the moment they click through, **before** the redirect leaves this application — which is the
 * only moment at which who they are is knowable. Order58's demo document carries no field this
 * application controls, so attribution that is not captured here cannot be recovered afterwards from
 * anywhere.
 */
final readonly class TestAttempt
{
    public function __construct(
        public int $id,
        public string $publicId,
        public int $storeSourceId,
        public int $sourceOrderId,
        public InitiatorType $initiatedByType,
        public int $initiatedById,
        public AttemptStatus $status,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $matchedAt = null,
        public ?int $matchedDemoOrderId = null,
        /** Resolved for display only; null when the account has since been removed. */
        public ?string $initiatedByName = null,
    ) {}

    /** Whether it still holds the one slot for its store and source order, at the given moment. */
    public function isOpenAt(DateTimeImmutable $now): bool
    {
        return $this->status->isOpen() && $this->expiresAt > $now;
    }
}
