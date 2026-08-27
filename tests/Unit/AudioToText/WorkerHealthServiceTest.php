<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\WorkerHealthService;
use App\AudioToText\Domain\WorkerHeartbeat;
use App\AudioToText\Domain\WorkerHeartbeatRepositoryInterface;
use App\AudioToText\Domain\WorkerMode;
use App\AudioToText\Domain\WorkerProcessState;
use App\AudioToText\Domain\WorkerSchedulerState;
use App\Tests\Support\AudioToTextSettingsFactory;
use App\Tests\Support\MutableClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The distinction this whole mechanism exists for: **"Running" must mean a process is alive.**
 *
 * Under a systemd timer or cron there is no worker process between ticks. A liveness-only view would
 * report "Not running" every few seconds on a perfectly healthy deployment and — much worse — would
 * look identical once the timer actually stopped. Tracking the last tick separately is what lets the
 * healthy case read "Scheduled" and reserves "Not running" for the case that needs attention.
 */
final class WorkerHealthServiceTest extends TestCase
{
    private const NOW = '2026-08-26 12:00:00';

    public function testNoHeartbeatEverWrittenIsUnknown(): void
    {
        $status = $this->serviceWith(null)->status();

        $this->assertFalse($status->everRan);
        $this->assertSame('Audio worker: Unknown', $status->label());
        $this->assertFalse($status->isHealthy());
    }

    public function testAFreshBusyContinuousWorkerIsRunning(): void
    {
        $status = $this->statusFor(WorkerProcessState::BUSY, WorkerMode::CONTINUOUS, beatAgo: 2, tickAgo: 2);

        $this->assertSame('Audio worker: Running (processing a job)', $status->label());
        $this->assertTrue($status->isProcessAlive());
        $this->assertTrue($status->isHealthy());
    }

    public function testAFreshIdleContinuousWorkerIsRunning(): void
    {
        $status = $this->statusFor(WorkerProcessState::IDLE, WorkerMode::CONTINUOUS, beatAgo: 3, tickAgo: 3);

        $this->assertSame('Audio worker: Running', $status->label());
        $this->assertTrue($status->isHealthy());
    }

    public function testAStoppedContinuousWorkerIsNotRunning(): void
    {
        $status = $this->statusFor(WorkerProcessState::IDLE, WorkerMode::CONTINUOUS, beatAgo: 600, tickAgo: 600);

        $this->assertSame('Audio worker: Not running', $status->label());
        $this->assertFalse($status->isProcessAlive());
        $this->assertFalse($status->isHealthy());
        $this->assertSame(
            'Queued jobs will remain pending until the worker starts.',
            $status->detail(),
        );
    }

    /**
     * The case the plan was corrected for: a timer between ticks. No process exists, but the schedule is
     * alive, and the page must say so rather than claiming the worker is running.
     */
    public function testATimerBetweenTicksReadsScheduledAndNeverRunning(): void
    {
        $status = $this->statusFor(WorkerProcessState::IDLE, WorkerMode::ONCE, beatAgo: 34, tickAgo: 34);

        $this->assertFalse($status->isProcessAlive());
        $this->assertSame(WorkerSchedulerState::TICKING, $status->scheduler);
        $this->assertSame('Audio worker: Scheduled — last ran 34 seconds ago', $status->label());
        $this->assertStringNotContainsString('Running', $status->label());
        // Still healthy: a queued job starts on the next tick without anyone intervening.
        $this->assertTrue($status->isHealthy());
    }

    public function testATimerBetweenTicksPluralisesMinutes(): void
    {
        $status = $this->statusFor(WorkerProcessState::IDLE, WorkerMode::ONCE, beatAgo: 130, tickAgo: 130);

        $this->assertSame('Audio worker: Scheduled — last ran 2 minutes ago', $status->label());
    }

    /**
     * The counterpart: ticks themselves have stopped. Same absent process, entirely different meaning.
     */
    public function testAStoppedTimerReadsNotRunning(): void
    {
        $status = $this->statusFor(WorkerProcessState::IDLE, WorkerMode::ONCE, beatAgo: 4000, tickAgo: 4000);

        $this->assertSame(WorkerSchedulerState::STALLED, $status->scheduler);
        $this->assertSame('Audio worker: Not running', $status->label());
        $this->assertFalse($status->isHealthy());
    }

    /** A tick actively processing a job says so, without claiming to be a long-running worker. */
    public function testATimerMidJobSaysProcessing(): void
    {
        $status = $this->statusFor(WorkerProcessState::BUSY, WorkerMode::ONCE, beatAgo: 1, tickAgo: 1);

        $this->assertSame('Audio worker: Processing a job', $status->label());
    }

    /**
     * A stalled queue has to explain itself. A deferral that rendered as "Running" would leave an
     * administrator watching a queue that never moves with no idea why.
     */
    public function testADeferringWorkerSaysSo(): void
    {
        $status = $this->statusFor(WorkerProcessState::DEFERRED, WorkerMode::CONTINUOUS, beatAgo: 2, tickAgo: 2);

        $this->assertSame('Audio worker: Deferring new jobs while the server is busy', $status->label());
        $this->assertSame('Queued jobs will start as soon as the server has capacity.', $status->detail());
    }

    /**
     * Clock skew between the worker host and this one must not be read as a dead worker.
     */
    public function testABeatFromTheFutureIsTreatedAsFresh(): void
    {
        $status = $this->statusFor(WorkerProcessState::IDLE, WorkerMode::CONTINUOUS, beatAgo: -5, tickAgo: -5);

        $this->assertTrue($status->isProcessAlive());
    }

    private function statusFor(
        WorkerProcessState $state,
        WorkerMode $mode,
        int $beatAgo,
        int $tickAgo,
    ): \App\AudioToText\Domain\WorkerStatusView {
        $now = new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));

        $heartbeat = new WorkerHeartbeat(
            $now->modify('-1 hour'),
            $now->modify(($beatAgo >= 0 ? '-' : '+') . abs($beatAgo) . ' seconds'),
            $state,
            $now->modify(($tickAgo >= 0 ? '-' : '+') . abs($tickAgo) . ' seconds'),
            $mode,
            'EMPTY',
            null,
        );

        return $this->serviceWith($heartbeat)->status();
    }

    private function serviceWith(?WorkerHeartbeat $heartbeat): WorkerHealthService
    {
        $repository = new class ($heartbeat) implements WorkerHeartbeatRepositoryInterface {
            public function __construct(private readonly ?WorkerHeartbeat $heartbeat) {}

            public function read(): ?WorkerHeartbeat
            {
                return $this->heartbeat;
            }

            public function beat(
                WorkerProcessState $state,
                WorkerMode $mode,
                bool $tick,
                ?string $tickOutcome = null,
                ?int $currentJobId = null,
            ): void {
                // Not exercised here.
            }
        };

        return new WorkerHealthService(
            $repository,
            AudioToTextSettingsFactory::create(),
            new MutableClock(self::NOW),
        );
    }
}
