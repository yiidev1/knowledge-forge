<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

interface WorkerHeartbeatRepositoryInterface
{
    public function read(): ?WorkerHeartbeat;

    /**
     * Upserts the single heartbeat row.
     *
     * `$tick` distinguishes the two things this table tracks. Passing true stamps `last_tick_at` and is
     * done once per invocation — including by a `--once` tick that finds nothing to do, which is exactly
     * the case that proves a schedule is alive. Passing false refreshes only the liveness beat.
     */
    public function beat(
        WorkerProcessState $state,
        WorkerMode $mode,
        bool $tick,
        ?string $tickOutcome = null,
        ?int $currentJobId = null,
    ): void;
}
