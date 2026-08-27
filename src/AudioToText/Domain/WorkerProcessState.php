<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * Whether a worker process is alive right now, derived from the freshness of its heartbeat.
 *
 * This answers one question only — is something running this instant — and is deliberately not enough
 * on its own to describe a tick-based deployment. Under a systemd timer or cron there is no process
 * between ticks, so `ABSENT` there is normal rather than broken; {@see WorkerSchedulerState} supplies
 * the missing half.
 */
enum WorkerProcessState: string
{
    /** Heartbeat is fresh and a job is claimed. */
    case BUSY = 'BUSY';
    /** Heartbeat is fresh and the worker is waiting for work. */
    case IDLE = 'IDLE';
    /** Heartbeat is fresh, but admission control is holding jobs back. */
    case DEFERRED = 'DEFERRED';
    /** No heartbeat within the staleness window: no worker process is alive. */
    case ABSENT = 'ABSENT';

    public static function fromStorage(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::ABSENT;
    }
}
