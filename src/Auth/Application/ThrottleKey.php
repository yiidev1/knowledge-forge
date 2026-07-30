<?php

declare(strict_types=1);

namespace App\Auth\Application;

/**
 * Derives the throttle bucket for an attempt.
 *
 * A failure counts against the (username, client IP) pair, so guessing one account from one address is
 * limited, while a legitimate user on a different address is unaffected. The identifiers are hashed so
 * the throttle table never stores a plaintext username or IP.
 */
final class ThrottleKey
{
    public static function for(string $username, string $clientIp): string
    {
        return hash('sha256', strtolower($username) . '|' . $clientIp);
    }
}
