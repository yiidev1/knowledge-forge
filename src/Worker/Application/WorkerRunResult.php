<?php

declare(strict_types=1);

namespace App\Worker\Application;

/**
 * The outcome of one worker run, carrying just enough to derive a process exit code for cron/monitoring.
 *
 * Exit codes: 0 — nothing to do or everything succeeded (including the lock being held by another run,
 * which is normal); 1 — at least one item failed while the run itself was healthy; 70 (EX_SOFTWARE) —
 * an infrastructure fault (a drainer threw unexpectedly, e.g. the database was unreachable).
 */
final readonly class WorkerRunResult
{
    public function __construct(
        public bool $lockAcquired,
        public int $processed,
        public int $failed,
        public bool $infraFailure,
    ) {}

    public static function lockHeld(): self
    {
        return new self(false, 0, 0, false);
    }

    public function exitCode(): int
    {
        if ($this->infraFailure) {
            return 70;
        }

        return $this->failed > 0 ? 1 : 0;
    }
}
