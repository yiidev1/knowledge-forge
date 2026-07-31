<?php

declare(strict_types=1);

namespace App\Order58\Client;

/**
 * Timeout and retry budget for Order58 traffic. There is one profile: all Order58 calls run in the cron
 * worker (never a web request), so it can afford patient timeouts and a few bounded retries.
 */
final readonly class Order58HttpProfile
{
    public function __construct(
        public string $name,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
        public int $maxRetries,
        public int $maxBackoffSeconds,
    ) {}
}
