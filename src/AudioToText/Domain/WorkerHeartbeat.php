<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use DateTimeImmutable;

/**
 * The single heartbeat row, as stored.
 *
 * Purely informational. It records what the worker is doing; it never decides what the worker may do.
 * The authority on "only one worker" remains the `flock` on `worker.lock`, and the authority on "only
 * one process takes this job" remains the conditional `UPDATE`. If this table were dropped mid-run the
 * worker would keep working correctly and only the admin page would lose its status line.
 */
final readonly class WorkerHeartbeat
{
    public function __construct(
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $beatAt,
        public WorkerProcessState $state,
        public DateTimeImmutable $lastTickAt,
        public WorkerMode $mode,
        public string $lastTickOutcome,
        public ?int $currentJobId,
    ) {}
}
