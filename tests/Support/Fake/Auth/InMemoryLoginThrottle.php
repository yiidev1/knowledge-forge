<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Auth;

use App\Auth\Application\LoginThrottleInterface;

/**
 * Controllable throttle: a test sets the lock state and inspects the recorded failure/clear calls.
 */
final class InMemoryLoginThrottle implements LoginThrottleInterface
{
    public int $failureCalls = 0;
    public int $clearCalls = 0;

    public function __construct(
        private ?int $retryAfterSeconds = null,
    ) {}

    public function lock(int $retryAfterSeconds): void
    {
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    public function retryAfterSeconds(string $key): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function registerFailure(string $key): void
    {
        $this->failureCalls++;
    }

    public function clear(string $key): void
    {
        $this->clearCalls++;
    }
}
