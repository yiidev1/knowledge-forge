<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\WorkerHeartbeatRepositoryInterface;
use App\AudioToText\Domain\WorkerProcessState;
use App\AudioToText\Domain\WorkerSchedulerState;
use App\AudioToText\Domain\WorkerStatusView;
use App\Shared\Domain\Clock\ClockInterface;
use Throwable;

/**
 * Turns the heartbeat row into what the admin page says.
 *
 * Two independent readings, deliberately kept apart:
 *
 * - `beat_at` answers "is a worker process alive right now".
 * - `last_tick_at` answers "is something still invoking the worker".
 *
 * Collapsing them would produce the bug this class exists to avoid. Under a systemd timer there is no
 * process between ticks, so a liveness-only view would report "Not running" every few seconds on a
 * perfectly healthy deployment — and, worse, would look exactly the same once the timer genuinely
 * stopped. Reading both means the page can say "Scheduled, last ran 34 seconds ago" for the healthy
 * case and reserve "Not running" for the one that needs attention.
 */
final readonly class WorkerHealthService
{
    public function __construct(
        private WorkerHeartbeatRepositoryInterface $heartbeats,
        private AudioToTextSettings $settings,
        private ClockInterface $clock,
    ) {}

    public function status(): WorkerStatusView
    {
        try {
            $heartbeat = $this->heartbeats->read();
        } catch (Throwable) {
            // The status line is decoration; a database hiccup reading it must not take down the page
            // whose whole purpose is to let someone upload a recording.
            return WorkerStatusView::neverRan();
        }

        if ($heartbeat === null) {
            return WorkerStatusView::neverRan();
        }

        $now = $this->clock->now()->getTimestamp();
        $sinceBeat = $now - $heartbeat->beatAt->getTimestamp();
        $sinceTick = $now - $heartbeat->lastTickAt->getTimestamp();

        // A beat from the future means clock skew between the worker host and this one. Treating it as
        // fresh is the forgiving reading, and the alternative — declaring a running worker dead because
        // its clock is two seconds ahead — is worse.
        $processAlive = $sinceBeat <= $this->settings->worker->staleAfterSeconds;

        $process = $processAlive ? $heartbeat->state : WorkerProcessState::ABSENT;

        $scheduler = $sinceTick <= $this->settings->worker->tickStaleAfterSeconds
            ? WorkerSchedulerState::TICKING
            : WorkerSchedulerState::STALLED;

        return new WorkerStatusView(
            $heartbeat->mode,
            $process,
            $scheduler,
            max(0, $sinceTick),
            true,
        );
    }
}
