<?php

declare(strict_types=1);

namespace App\Auth\Application;

/**
 * Tuning for {@see LoginThrottleInterface}.
 */
final readonly class ThrottleParams
{
    public function __construct(
        public int $maxAttempts,
        public int $windowMinutes,
        public int $lockoutMinutes,
    ) {}
}
