<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\Clock\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A clock a test can move forward, so time-dependent behaviour (throttle windows, backoff, stuck
 * recovery) is exercised deterministically instead of with sleeps.
 */
final class MutableClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $start = '2026-01-01 00:00:00')
    {
        $this->now = new DateTimeImmutable($start, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
