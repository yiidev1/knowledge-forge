<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Client;

/**
 * Pauses execution between retry attempts. Injected so tests can assert the backoff schedule without
 * actually waiting.
 */
interface SleeperInterface
{
    public function sleep(float $seconds): void;
}
