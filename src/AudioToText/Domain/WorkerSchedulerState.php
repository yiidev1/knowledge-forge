<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * Whether *something* is invoking the worker on a schedule, derived from `last_tick_at`.
 *
 * Every invocation stamps that column — including a `--once` tick that finds an empty queue and exits
 * in milliseconds. That is what makes "the timer is alive, we are simply between ticks" distinguishable
 * from "the timer stopped an hour ago", which no amount of process-liveness checking can tell apart.
 */
enum WorkerSchedulerState: string
{
    /** A tick landed within the tick-staleness window. */
    case TICKING = 'TICKING';
    /** Ticks have stopped: nothing has invoked the worker recently. */
    case STALLED = 'STALLED';
    /** No worker has ever run against this database. */
    case UNKNOWN = 'UNKNOWN';
}
