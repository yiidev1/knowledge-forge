<?php

declare(strict_types=1);

namespace App\Worker\Application;

use Throwable;

/**
 * Runs one pass of every drainer under a single-holder lock.
 *
 * The lock is non-blocking: if another worker (or the previous cron minute) still holds it, this run
 * exits immediately as a no-op — overlapping runs never stack. Each drainer recovers stranded items and
 * then drains up to $limit; drainers are independent, so one throwing does not stop the rest, but it
 * does mark the whole run as an infrastructure failure so cron/monitoring notices.
 */
final readonly class WorkerRunner
{
    /**
     * @param iterable<DrainerInterface> $drainers Ordered: provision first, then process, then clean up.
     */
    public function __construct(
        private WorkerLockInterface $lock,
        private iterable $drainers,
    ) {}

    public function run(int $limit): WorkerRunResult
    {
        if (!$this->lock->acquire()) {
            return WorkerRunResult::lockHeld();
        }

        $processed = 0;
        $failed = 0;
        $infraFailure = false;

        try {
            foreach ($this->drainers as $drainer) {
                try {
                    $drainer->recover();
                    $result = $drainer->drain($limit);
                    $processed += $result->processed;
                    $failed += $result->failed;
                } catch (Throwable) {
                    // A drainer should handle its own item-level errors; an escape here is infrastructure
                    // (DB down, disk full). Record it and let the other drainers still run.
                    $infraFailure = true;
                }
            }
        } finally {
            $this->lock->release();
        }

        return new WorkerRunResult(true, $processed, $failed, $infraFailure);
    }
}
