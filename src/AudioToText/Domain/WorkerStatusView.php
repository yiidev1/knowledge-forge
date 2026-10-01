<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use function floor;
use function sprintf;

/**
 * What the admin page says about whether transcribing is happening.
 *
 * The rule this class exists to enforce: **"is running" appears only when a process is genuinely alive.**
 * Under a systemd timer or cron there is no process between ticks, and calling that "running" would be a
 * plain untruth that also hides a real failure — a timer that has actually stopped looks identical to one
 * that is merely idle if liveness is all you track. So a tick deployment between ticks reads "scheduled —
 * last ran 34 seconds ago", and only says "is not running" once ticks themselves have stopped.
 *
 * ## Why none of these sentences name the worker
 *
 * They used to: "Audio worker: Running", "Queued jobs will start as soon as the server has capacity." Each
 * was accurate about this application's internals and silent about the only question the reader has, which
 * is whether their recordings are moving. An administrator cannot see a worker, start one, or join a queue;
 * what they can do is wait or go and investigate, and that decision is made from *whether transcribing is
 * running*, which is what these now say. The mechanism keeps its own names in {@see WorkerProcessState},
 * the commands, and the logs — this class is the one place the two vocabularies meet.
 *
 * Nothing here exposes a PID, a path, a command line or an internal row id.
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

    /** Whether waiting work can be expected to move without operator intervention. */
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
            return 'Transcribing: unknown';
        }

        return match ($this->process) {
            WorkerProcessState::BUSY => $this->mode === WorkerMode::ONCE
                ? 'Transcribing a recording'
                : 'Transcribing is running (a recording is in progress)',
            WorkerProcessState::IDLE => 'Transcribing is running',
            WorkerProcessState::DEFERRED => 'Paused while the server is busy',
            WorkerProcessState::ABSENT => $this->absentLabel(),
        };
    }

    /**
     * The sentence under the label, or null when the state needs no elaboration. This is where a stall
     * explains itself instead of looking like a dead application.
     */
    public function detail(): ?string
    {
        if (!$this->everRan) {
            return 'Transcribing has not run yet on this server.';
        }

        if ($this->process === WorkerProcessState::DEFERRED) {
            return 'Waiting recordings will start when the server has capacity.';
        }

        if ($this->process !== WorkerProcessState::ABSENT) {
            return null;
        }

        return $this->mode === WorkerMode::ONCE && $this->scheduler === WorkerSchedulerState::TICKING
            ? 'The schedule is active. A waiting recording starts on the next run.'
            : 'Waiting recordings will not start until transcribing is running.';
    }

    private function absentLabel(): string
    {
        if ($this->mode === WorkerMode::ONCE && $this->scheduler === WorkerSchedulerState::TICKING) {
            return 'Transcribing is scheduled — last ran ' . self::humanize($this->secondsSinceLastTick) . ' ago';
        }

        return 'Transcribing is not running';
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
