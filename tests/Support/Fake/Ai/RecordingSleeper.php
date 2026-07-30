<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Ai;

use App\Ai\OpenAi\Client\SleeperInterface;

/**
 * Records requested sleep durations instead of waiting, so retry-timing tests run instantly and can
 * assert the backoff schedule.
 */
final class RecordingSleeper implements SleeperInterface
{
    /** @var list<float> */
    public array $sleeps = [];

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
    }
}
