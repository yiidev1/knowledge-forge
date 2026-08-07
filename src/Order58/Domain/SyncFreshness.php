<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use DateTimeImmutable;

/**
 * The freshness of one Order58 sync type: its overall {@see SyncFreshnessState}, the last *successful* sync time
 * (never overwritten by a later failed run), the last *attempted* run time and that attempt's status, and
 * whether a run is in flight. All timestamps are UTC (the display timezone seam is applied at render).
 */
final readonly class SyncFreshness
{
    public function __construct(
        public Order58SyncType $type,
        public SyncFreshnessState $state,
        public ?DateTimeImmutable $lastSuccessAt,
        public ?DateTimeImmutable $lastAttemptAt,
        public ?SyncRunStatus $lastAttemptStatus,
        public bool $syncing,
        /** The next scheduled daily run (UTC instant), or null for types with no daily schedule. */
        public ?DateTimeImmutable $nextScheduledAt = null,
    ) {}

    public function hasEverSucceeded(): bool
    {
        return $this->lastSuccessAt !== null;
    }
}
