<?php

declare(strict_types=1);

namespace App\Auth\Application;

/**
 * Rate-limits authentication attempts to blunt password guessing.
 *
 * Keyed on the caller-supplied identifier (in practice a hash of username + client IP), so a single
 * account cannot be hammered and a single address cannot spray many accounts, while a legitimate user
 * elsewhere is unaffected.
 */
interface LoginThrottleInterface
{
    /**
     * @return int|null Seconds remaining until the caller may try again, or null when not locked.
     */
    public function retryAfterSeconds(string $key): ?int;

    /**
     * Records a failed attempt, opening or extending the lockout window as configured.
     */
    public function registerFailure(string $key): void;

    /**
     * Clears all recorded attempts for the key, called after a successful login.
     */
    public function clear(string $key): void;
}
