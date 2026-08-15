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
 * The daily agents schedule — the 01:00 New York slot that keeps `order58_agents` fresh.
 *
 * This one carries more weight than the other two schedules: the fallback agent login refuses a mirror row
 * last synced more than `ORDER58_VALIDATE_MAX_MIRROR_AGE_HOURS` (72h) ago, so a missing agents cadence turns
 * into refused logins rather than merely stale reporting data. Before this schedule existed the mirror was
 * refreshed only when an admin pressed a button, and it had gone ten days without a run.
 *
 * The mechanism itself is the existing {@see DailySyncScheduler}; what is asserted here is that agents is
 * wired into it correctly and does not disturb Knowledge (02:00) or Rules (03:00).
 */
final class AgentsSyncScheduleTest extends Unit
{
    private const HOUR = 1;

    private InMemorySyncRunRepository $runs;
    private InMemoryDailySyncScheduleRepository $schedules;
    private MutableClock $clock;
    private DailySyncScheduler $scheduler;

    protected function _before(): void
    {
        $this->runs = new InMemorySyncRunRepository();
        $this->schedules = new InMemoryDailySyncScheduleRepository();
        // 2026-07-15 12:00 UTC = 08:00 EDT — past 01:00 NY, so the day is due.
        $this->clock = new MutableClock('2026-07-15 12:00:00');
        $this->scheduler = $this->schedulerAt($this->clock);
    }

    public function testEnqueuesAnAgentsRunAndNothingElse(): void
    {
        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Agents, self::HOUR));

        assertSame(['agents'], $this->runs->enqueued, 'only an agents run — not knowledge, not rules');
        assertSame('enqueued', $this->schedules->find('agents', '2026-07-15')['status']);
        assertSame(1, $this->schedules->rowCount());
    }

    public function testNotDueBeforeOneAmNewYork(): void
    {
        // 04:00 UTC = 00:00 EDT — the NY day has begun but the 01:00 hour has not passed.
        $scheduler = $this->schedulerAt(new MutableClock('2026-07-15 04:00:00'));

        assertSame(DailySyncOutcome::NotDue, $scheduler->schedule(Order58SyncType::Agents, self::HOUR));
        assertSame(0, $this->schedules->rowCount());
        assertSame([], $this->runs->enqueued);
    }

    public function testDueFromOneAmNewYorkOnwards(): void
    {
        // 05:00 UTC = 01:00 EDT exactly.
        $scheduler = $this->schedulerAt(new MutableClock('2026-07-15 05:00:00'));

        assertSame(DailySyncOutcome::Enqueued, $scheduler->schedule(Order58SyncType::Agents, self::HOUR));
    }

    public function testIdempotentForTheSameNewYorkDate(): void
    {
        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Agents, self::HOUR));
        assertSame(DailySyncOutcome::AlreadyScheduled, $this->scheduler->schedule(Order58SyncType::Agents, self::HOUR));
        assertSame(DailySyncOutcome::AlreadyScheduled, $this->scheduler->schedule(Order58SyncType::Agents, self::HOUR));

        assertCount(1, $this->runs->enqueued, 'an hourly cron must not enqueue twenty-three extra runs');
        assertSame(1, $this->schedules->rowCount());
    }

    /** An hourly cron is the deployment shape, so a pass long after 01:00 must still cover the day. */
    public function testACatchUpPassLaterInTheDayStillEnqueues(): void
    {
        // 13:00 UTC = 09:00 EDT, eight hours late after downtime.
        $scheduler = $this->schedulerAt(new MutableClock('2026-07-15 13:00:00'));

        assertSame(DailySyncOutcome::Enqueued, $scheduler->schedule(Order58SyncType::Agents, self::HOUR));
        assertSame('enqueued', $this->schedules->find('agents', '2026-07-15')['status']);
    }

    public function testTheNextDayGetsItsOwnRun(): void
    {
        $this->scheduler->schedule(Order58SyncType::Agents, self::HOUR);
        $this->clock->advance('+1 day');

        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Agents, self::HOUR));
        assertSame(2, $this->schedules->rowCount());
        assertCount(2, $this->runs->enqueued);
    }

    public function testAFailedEnqueueIsRetriedByTheNextPass(): void
    {
        $this->runs->throwOnEnqueue = true;
        assertSame(DailySyncOutcome::Failed, $this->scheduler->schedule(Order58SyncType::Agents, self::HOUR));
        assertSame('failed', $this->schedules->find('agents', '2026-07-15')['status']);

        $this->runs->throwOnEnqueue = false;
        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Agents, self::HOUR));
        assertSame('enqueued', $this->schedules->find('agents', '2026-07-15')['status']);
        assertSame(1, $this->schedules->rowCount(), 'the same reservation is reused, not duplicated');
    }

    public function testCoalescingOntoAnActiveRunSatisfiesTheDay(): void
    {
        // A manual "Sync Agents" from the admin UI is already running.
        $this->runs->coalesceEnqueue = true;

        assertSame(DailySyncOutcome::Coalesced, $this->scheduler->schedule(Order58SyncType::Agents, self::HOUR));
        assertNull($this->schedules->find('agents', '2026-07-15')['integration_sync_run_id']);
        assertSame(DailySyncOutcome::AlreadyScheduled, $this->scheduler->schedule(Order58SyncType::Agents, self::HOUR));
    }

    // ---------------------------------------------------------------- independence

    public function testAllThreeSchedulesCoexistOnOneDay(): void
    {
        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Agents, 1));
        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Knowledge, 2));
        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Rules, 3));

        assertSame(['agents', 'knowledge', 'rules'], $this->runs->enqueued);
        assertSame(3, $this->schedules->rowCount());
        foreach (['agents', 'knowledge', 'rules'] as $type) {
            assertSame('enqueued', $this->schedules->find($type, '2026-07-15')['status']);
        }
    }

    public function testAnAgentsFailureLeavesKnowledgeAndRulesUntouched(): void
    {
        $this->runs->throwOnEnqueue = true;
        $this->scheduler->schedule(Order58SyncType::Agents, 1);

        $this->runs->throwOnEnqueue = false;
        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Knowledge, 2));
        assertSame(DailySyncOutcome::Enqueued, $this->scheduler->schedule(Order58SyncType::Rules, 3));

        assertSame('failed', $this->schedules->find('agents', '2026-07-15')['status']);
        assertSame(['knowledge', 'rules'], $this->runs->enqueued);
    }

    public function testSchedulingAgentsDoesNotSatisfyTheOtherTypes(): void
    {
        $this->scheduler->schedule(Order58SyncType::Agents, 1);

        assertNull($this->schedules->find('knowledge', '2026-07-15'));
        assertNull($this->schedules->find('rules', '2026-07-15'));
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
