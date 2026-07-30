<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Client;

use function max;
use function usleep;

/**
 * Real sleeper backed by usleep().
 */
final class UsleepSleeper implements SleeperInterface
{
    public function sleep(float $seconds): void
    {
        $microseconds = (int) (max(0.0, $seconds) * 1_000_000);
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }
}
