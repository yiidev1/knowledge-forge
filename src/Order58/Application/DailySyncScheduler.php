<?php

declare(strict_types=1);

namespace App\Order58\Application;

use App\Order58\Domain\DailySyncScheduleRepositoryInterface;
use App\Order58\Domain\Order58SyncType;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Clock\ClockInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

use function mb_substr;

/**
 * Enqueues one Order58 sync type once per New-York calendar day at a fixed local hour (Knowledge 02:00, Rules
 * 03:00) — enqueue-only: it inserts an `integration_sync_runs` row and returns; the worker performs all API work.
 *
 * Failure-safe + catch-up-aware:
 *  - It acts only once the day's wall-clock time (in APP_TIMEZONE) has passed, so running it hourly recovers a
 *    day whose exact minute was missed during downtime, without ever firing early.
 *  - The `order58_daily_sync_schedules` reservation (UNIQUE per type+NY-date) is claimed first; it counts as done
 *    only when `enqueued`. The enqueue and the status flip commit together in one transaction, so a crash rolls
 *    both back (retryable next pass) and a committed `enqueued` guarantees at most ONE successful scheduled run
 *    per type per day. A coalesce onto an already-active run also satisfies the day.
 *  - Manual admin syncs never touch this table, so they are never blocked.
 *
 * DB timestamps stay UTC; only the calendar-date / due-time decisions use the configured application timezone.
 */
final readonly class DailySyncScheduler
{
    public function __construct(
        private AppTimeZone $appTimeZone,
        private DailySyncScheduleRepositoryInterface $schedules,
        private EnqueueSyncService $enqueue,
        private TransactionRunnerInterface $transaction,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function schedule(Order58SyncType $type, int $hour): DailySyncOutcome
    {
        $now = $this->clock->now();
        $nyDate = $this->appTimeZone->businessDate($now);

        if (!$this->appTimeZone->isDailyDue($now, $hour)) {
            return $this->done($type, $hour, $nyDate, $now, DailySyncOutcome::NotDue, null);
        }

        $existing = $this->schedules->find($type->value, $nyDate);
        if ($existing !== null && $existing['status'] === 'enqueued') {
            return $this->done($type, $hour, $nyDate, $now, DailySyncOutcome::AlreadyScheduled, $existing['integration_sync_run_id']);
        }

        $id = $existing['id'] ?? $this->schedules->reserve($type->value, $nyDate, $now);
        if ($id === null) {
            // Lost the insert race — re-read; if the winner already enqueued we are done, else retry its row.
            $existing = $this->schedules->find($type->value, $nyDate);
            if ($existing === null || $existing['status'] === 'enqueued') {
                return $this->done($type, $hour, $nyDate, $now, DailySyncOutcome::AlreadyScheduled, $existing['integration_sync_run_id'] ?? null);
            }
            $id = $existing['id'];
        }

        try {
            $runId = $this->transaction->run(function () use ($type, $id, $now): ?int {
                // A null id means an active run already exists (manual or a prior pass) — the day is satisfied.
                $runId = $this->enqueue->enqueueReturningId($type, null, null);
                $this->schedules->markEnqueued($id, $runId, $now);

                return $runId;
            });
        } catch (Throwable $e) {
            $this->schedules->markFailed($id, mb_substr($e->getMessage(), 0, 1000), $now);

            return $this->done($type, $hour, $nyDate, $now, DailySyncOutcome::Failed, null, $e::class);
        }

        $outcome = $runId === null ? DailySyncOutcome::Coalesced : DailySyncOutcome::Enqueued;

        return $this->done($type, $hour, $nyDate, $now, $outcome, $runId);
    }

    private function done(
        Order58SyncType $type,
        int $hour,
        string $nyDate,
        DateTimeImmutable $now,
        DailySyncOutcome $outcome,
        ?int $runId,
        ?string $error = null,
    ): DailySyncOutcome {
        // Structured, secret-free: instant (UTC + NY), NY date, type, run id, outcome — never a payload/token.
        $this->logger->info('order58 daily sync scheduler', [
            'sync_type' => $type->value,
            'scheduled_hour_local' => $hour,
            'ny_date' => $nyDate,
            'invoked_at_utc' => $now->format('Y-m-d H:i:s'),
            'invoked_at_local' => $this->appTimeZone->format($now, 'Y-m-d H:i:s T'),
            'outcome' => $outcome->value,
            'integration_sync_run_id' => $runId,
            'error_class' => $error,
        ]);

        return $outcome;
    }
}
