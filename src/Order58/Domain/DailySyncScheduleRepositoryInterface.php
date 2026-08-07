<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use DateTimeImmutable;

/**
 * Persistence for the per-New-York-day daily-sync reservations (`order58_daily_sync_schedules`). The unique
 * `(sync_type, ny_date)` is the durable idempotency guard; a reservation only counts as "done" once its status
 * is `enqueued`.
 */
interface DailySyncScheduleRepositoryInterface
{
    /**
     * The reservation for a type on an application-zone calendar date, or null if none exists yet.
     *
     * @return array{id: int, status: string, integration_sync_run_id: int|null}|null
     */
    public function find(string $syncType, string $nyDate): ?array;

    /**
     * Inserts a new `pending` reservation and returns its id, or null when one already exists for
     * `(sync_type, ny_date)` (the UNIQUE index rejected the insert — a concurrent scheduler won the race).
     */
    public function reserve(string $syncType, string $nyDate, DateTimeImmutable $now): ?int;

    /**
     * Marks a reservation `enqueued` with the run it enqueued (null when coalesced onto an already-active run) —
     * the point at which the day is guaranteed satisfied for that type.
     */
    public function markEnqueued(int $id, ?int $runId, DateTimeImmutable $now): void;

    /**
     * Marks a reservation `failed` (incrementing attempts) so a later catch-up pass retries it.
     */
    public function markFailed(int $id, string $error, DateTimeImmutable $now): void;
}
