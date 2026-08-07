<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\DailySyncOutcome;
use App\Order58\Application\DailySyncScheduler;
use App\Order58\Application\EnqueueSyncService;
use App\Order58\Domain\Order58SyncType;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Tests\Support\Fake\Order58\InMemoryDailySyncScheduleRepository;
use App\Tests\Support\Fake\Order58\InMemorySyncRunRepository;
use App\Tests\Support\MutableClock;
use Closure;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

/**
 * The daily scheduler's failure-safe, catch-up-aware, per-NY-day-idempotent behaviour, driven deterministically
 * with a mutable clock: it enqueues only once the local wall-clock time has passed, records the enqueued run,
 * never schedules a type twice on the same NY day, allows the next day, keeps Knowledge and Rules independent,
 * treats a coalesce as satisfying the day, and leaves a failed enqueue retryable.
 */
final class DailySyncSchedulerTest extends Unit
{
    private InMemorySyncRunRepository $runs;
    private InMemoryDailySyncScheduleRepository $schedules;
    private MutableClock $clock;
    private DailySyncScheduler $scheduler;

    protected function _before(): void
    {
        $this->runs = new InMemorySyncRunRepository();
        $this->schedules = new InMemoryDailySyncScheduleRepository();
        // 2026-07-15 12:00 UTC = 08:00 EDT — past both 02:00 and 03:00 NY, so the day is due.
        $this->clock = new MutableClock('2026-07-15 12:00:00');
        $tx = new class implements TransactionRunnerInterface {
            public function run(Closure $work): mixed
            {
                return $work();
            }
        };
        $this->scheduler = new DailySyncScheduler(
            new AppTimeZone('America/New_York'),
            $this->schedules,
            new EnqueueSyncService($this->runs, $this->clock),
            $tx,
            $this->clock,
            new NullLogger(),
        );
    }

    public function testDueEnqueuesOnceAndRecordsTheRunThenIsIdempotent(): void
    {
        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Rules, 3));

        $row = $this->schedules->find('rules', '2026-07-15');
        assertSame('enqueued', $row['status']);
        assertSame(1, $row['integration_sync_run_id']);
        assertSame(['rules'], $this->runs->enqueued);

        // Second invocation on the same NY day is a no-op: no duplicate run, no duplicate reservation.
        assertSame(DailySyncOutcome::AlreadyScheduled, $this->scheduler->schedule(Order58SyncType::Rules, 3));
        assertSame(1, $this->schedules->rowCount());
        assertCount(1, $this->runs->enqueued);
    }

    public function testNotDueBeforeTheLocalHourDoesNothing(): void
    {
        // 06:00 UTC = 02:00 EDT — before the 03:00 rules hour.
        $this->clock = new MutableClock('2026-07-15 06:00:00');
        $scheduler = $this->schedulerAt($this->clock);

        assertSame(DailySyncOutcome::NotDue, $scheduler->schedule(Order58SyncType::Rules, 3));
        assertSame(0, $this->schedules->rowCount());
        assertSame([], $this->runs->enqueued);
    }

    public function testTomorrowIsAllowedAfterTodayIsScheduled(): void
    {
        $this->scheduler->schedule(Order58SyncType::Rules, 3);
        $this->clock->advance('+1 day');

        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Rules, 3));
        assertSame(2, $this->schedules->rowCount(), 'a new NY date gets its own reservation');
        assertCount(2, $this->runs->enqueued);
    }

    public function testKnowledgeAndRulesAreScheduledIndependently(): void
    {
        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Knowledge, 2));
        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Rules, 3));

        assertSame('enqueued', $this->schedules->find('knowledge', '2026-07-15')['status']);
        assertSame('enqueued', $this->schedules->find('rules', '2026-07-15')['status']);
        assertSame(2, $this->schedules->rowCount());
    }

    public function testCoalesceOntoAnActiveRunStillSatisfiesTheDay(): void
    {
        $this->runs->coalesceEnqueue = true; // an active run already exists

        assertSame(DailySyncOutcome::Coalesced, $this->scheduler->schedule(Order58SyncType::Rules, 3));
        $row = $this->schedules->find('rules', '2026-07-15');
        assertSame('enqueued', $row['status']);
        assertNull($row['integration_sync_run_id'], 'coalesced: no new run id');

        // Still idempotent for the rest of the day.
        assertSame(DailySyncOutcome::AlreadyScheduled, $this->scheduler->schedule(Order58SyncType::Rules, 3));
    }

    public function testFailedEnqueueLeavesTheReservationRetryableForCatchUp(): void
    {
        $this->runs->throwOnEnqueue = true;
        assertSame(DailySyncOutcome::Failed, $this->scheduler->schedule(Order58SyncType::Rules, 3));
        assertSame('failed', $this->schedules->find('rules', '2026-07-15')['status']);

        // A later catch-up pass (enqueue now works) retries the SAME reservation → enqueued, no duplicate row.
        $this->runs->throwOnEnqueue = false;
        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Rules, 3));
        assertSame('enqueued', $this->schedules->find('rules', '2026-07-15')['status']);
        assertSame(1, $this->schedules->rowCount());
    }

    private function schedulerAt(MutableClock $clock): DailySyncScheduler
    {
        $tx = new class implements TransactionRunnerInterface {
            public function run(Closure $work): mixed
            {
                return $work();
            }
        };

        return new DailySyncScheduler(
            new AppTimeZone('America/New_York'),
            $this->schedules,
            new EnqueueSyncService($this->runs, $clock),
            $tx,
            $clock,
            new NullLogger(),
        );
    }
}
