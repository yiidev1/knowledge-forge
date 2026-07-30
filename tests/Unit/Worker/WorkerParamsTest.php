<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\Worker\Application\WorkerParams;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;

/**
 * Exponential backoff: base × 2^(attempt-1), capped at one hour.
 */
final class WorkerParamsTest extends Unit
{
    private function params(): WorkerParams
    {
        return new WorkerParams(
            batchSize: 1,
            maxProcessingAttempts: 3,
            processingTimeoutMinutes: 20,
            retryBaseSeconds: 60,
            provisionMaxAttempts: 5,
            indexPollIntervalSeconds: 3,
        );
    }

    public function testBackoffGrowsExponentiallyFromTheBase(): void
    {
        $params = $this->params();

        assertSame(60, $params->backoffSeconds(1));
        assertSame(120, $params->backoffSeconds(2));
        assertSame(240, $params->backoffSeconds(3));
    }

    public function testBackoffIsCappedAtOneHour(): void
    {
        // A large attempt count would overflow past an hour without the cap.
        assertSame(3600, $this->params()->backoffSeconds(20));
    }

    public function testAttemptZeroOrLessDoesNotUnderflow(): void
    {
        assertSame(60, $this->params()->backoffSeconds(0));
    }
}
