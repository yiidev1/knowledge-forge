<?php

declare(strict_types=1);

namespace App\Worker\Application;

use function max;
use function min;

/**
 * Resolved worker tuning, built once from the environment and injected.
 *
 * These bound how hard the worker leans on a shared server (batch size), how many times a failing item
 * is retried before it is given up on (attempt caps), how long a crashed run's claim is honoured before
 * recovery reclaims it (stuck timeout), and the exponential-backoff base for transient retries.
 */
final readonly class WorkerParams
{
    public function __construct(
        public int $batchSize,
        public int $maxProcessingAttempts,
        public int $processingTimeoutMinutes,
        public int $retryBaseSeconds,
        public int $provisionMaxAttempts,
        public int $indexPollIntervalSeconds,
    ) {}

    /**
     * Exponential backoff for the Nth attempt (1-based), capped at one hour.
     */
    public function backoffSeconds(int $attempt): int
    {
        $seconds = $this->retryBaseSeconds * (2 ** max(0, $attempt - 1));

        return (int) min($seconds, 3600);
    }
}
