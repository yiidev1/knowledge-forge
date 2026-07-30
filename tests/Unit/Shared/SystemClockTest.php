<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Domain\Clock\SystemClock;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class SystemClockTest extends Unit
{
    /**
     * Everything the worker persists — claim markers, backoff deadlines, processed timestamps — is
     * written in UTC. A clock that returned server-local time would make those values mean different
     * instants depending on which process wrote them.
     */
    public function testNowIsAlwaysUtcRegardlessOfServerTimezone(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Asia/Kolkata');

        try {
            assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testNowAdvances(): void
    {
        $clock = new SystemClock();
        $first = $clock->now();
        usleep(1500);

        assertTrue($clock->now() >= $first);
    }
}
