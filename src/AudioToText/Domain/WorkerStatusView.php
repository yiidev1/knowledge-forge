<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use function floor;
use function sprintf;

/**
 * What the admin page says about the worker.
 *
 * The rule this class exists to enforce: **the word "Running" appears only when a worker process is
 * genuinely alive.** Under a systemd timer or cron there is no process between ticks, and calling that
 * "Running" would be a plain untruth that also hides a real failure — a timer that has actually stopped
 * looks identical to one that is merely idle if liveness is all you track. So a tick deployment between
 * ticks reads "Scheduled — last ran 34 seconds ago", and only says "Not running" once ticks themselves
 * have stopped.
 *
 * Nothing here exposes a PID, a path, a command line or a job id.
 */
final readonly class WorkerStatusView
{
    public function __construct(
        public WorkerMode $mode,
        public WorkerProcessState $process,
        public WorkerSchedulerState $scheduler,
        public ?int $secondsSinceLastTick,
        public bool $everRan,
    ) {}

    public static function neverRan(): self
    {
        return new self(
            WorkerMode::CONTINUOUS,
            WorkerProcessState::ABSENT,
            WorkerSchedulerState::UNKNOWN,
            null,
            false,
        );
    }

    /**
     * True only when a process is alive. A timer between ticks is emphatically not "running", which is
     * why the list page uses this to decide whether a stalled queue deserves a warning.
     */
    public function isProcessAlive(): bool
    {
        return $this->process !== WorkerProcessState::ABSENT;
    }

    /** Whether queued work can be expected to move without operator intervention. */
    public function isHealthy(): bool
    {
        if ($this->process === WorkerProcessState::BUSY || $this->process === WorkerProcessState::IDLE) {
            return true;
        }

        return $this->mode === WorkerMode::ONCE && $this->scheduler === WorkerSchedulerState::TICKING;
    }

    public function label(): string
    {
        if (!$this->everRan) {
            return 'Audio worker: Unknown';
        }

        return match ($this->process) {
            WorkerProcessState::BUSY => $this->mode === WorkerMode::ONCE
                ? 'Audio worker: Processing a job'
                : 'Audio worker: Running (processing a job)',
            WorkerProcessState::IDLE => 'Audio worker: Running',
            WorkerProcessState::DEFERRED => 'Audio worker: Deferring new jobs while the server is busy',
            WorkerProcessState::ABSENT => $this->absentLabel(),
        };
    }

    /**
     * The sentence under the label, or null when the state needs no elaboration. This is where a stalled
     * queue explains itself instead of looking like a dead application.
     */
    public function detail(): ?string
    {
        if (!$this->everRan) {
            return 'No transcription worker has run yet on this server.';
        }

        if ($this->process === WorkerProcessState::DEFERRED) {
            return 'Queued jobs will start as soon as the server has capacity.';
        }

        if ($this->process !== WorkerProcessState::ABSENT) {
            return null;
        }

        return $this->mode === WorkerMode::ONCE && $this->scheduler === WorkerSchedulerState::TICKING
            ? 'The schedule is active. A queued job starts on the next tick.'
            : 'Queued jobs will remain pending until the worker starts.';
    }

    private function absentLabel(): string
    {
        if ($this->mode === WorkerMode::ONCE && $this->scheduler === WorkerSchedulerState::TICKING) {
            return 'Audio worker: Scheduled — last ran ' . self::humanize($this->secondsSinceLastTick) . ' ago';
        }

        return 'Audio worker: Not running';
    }

    private static function humanize(?int $seconds): string
    {
        if ($seconds === null || $seconds < 0) {
            return 'recently';
        }

        if ($seconds < 60) {
            return sprintf('%d second%s', $seconds, $seconds === 1 ? '' : 's');
        }

        $minutes = (int) floor($seconds / 60);

        return sprintf('%d minute%s', $minutes, $minutes === 1 ? '' : 's');
    }
}
