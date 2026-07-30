<?php

declare(strict_types=1);

namespace App\Shared\Domain\Clock;

use DateTimeImmutable;

/**
 * Current time, injected rather than read from a global.
 *
 * Retry backoff, stuck-document recovery and reconcile windows are all time arithmetic. Injecting the
 * clock is what lets those be tested by moving time forward instead of by sleeping.
 */
interface ClockInterface
{
    /**
     * Current instant in UTC. All timestamps in this application are UTC.
     */
    public function now(): DateTimeImmutable;
}
