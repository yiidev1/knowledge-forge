<?php

declare(strict_types=1);

namespace App\Order58\Application;

use App\Order58\Domain\Order58SyncType;
use App\Order58\Domain\SyncRunRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;

/**
 * Enqueues a synchronization run from the admin UI. Returns immediately — no Order58 or OpenAI work happens
 * here; the cron worker performs it. Enqueue is coalescing: if an active run of the same operation already
 * exists, no duplicate is created (the button is also rendered disabled in that state).
 */
final readonly class EnqueueSyncService
{
    public function __construct(
        private SyncRunRepositoryInterface $runs,
        private ClockInterface $clock,
    ) {}

    /**
     * @return bool True if a run was queued; false if an active run of this operation already exists.
     */
    public function enqueue(Order58SyncType $type, ?int $scopeRef, ?int $requestedByAdminId): bool
    {
        return $this->enqueueReturningId($type, $scopeRef, $requestedByAdminId) !== null;
    }

    /**
     * Like {@see enqueue()} but returns the created run id (or null when coalesced by an existing active run),
     * so a caller — e.g. the daily scheduler — can record exactly which run it enqueued without a race-prone
     * "select the latest row" lookup. The id comes straight from the coalescing insert.
     */
    public function enqueueReturningId(Order58SyncType $type, ?int $scopeRef, ?int $requestedByAdminId): ?int
    {
        return $this->runs->enqueue($type, $scopeRef, $requestedByAdminId, $this->clock->now());
    }
}
