<?php

declare(strict_types=1);

namespace App\Order58\Client;

use App\Order58\Contract\Dto\Order58ErrorDetails;

use function max;
use function min;

/**
 * Decides whether and how long to wait before retrying a failed Order58 request.
 *
 * Pure and deterministic so the retry matrix is unit-testable. Only transient failures (429, 5xx, network)
 * are retried; authentication and validation failures are not. Backoff is exponential, capped by the
 * profile, and never shorter than a `Retry-After` the server asked for. Every Phase-1 call is a GET, so
 * there is no side-effect gate to apply.
 */
final readonly class Order58RetryPolicy
{
    private const BASE_DELAY_SECONDS = 0.5;

    public function __construct(
        private Order58HttpProfile $profile,
    ) {}

    /**
     * @param int $attempt 1-based count of failures so far (1 = the first failure).
     *
     * @return float|null Seconds to wait before retrying, or null to give up.
     */
    public function decideDelay(int $attempt, Order58ErrorDetails $error): ?float
    {
        if ($attempt > $this->profile->maxRetries) {
            return null;
        }

        if (!$error->transient) {
            return null;
        }

        $delay = min(
            (float) $this->profile->maxBackoffSeconds,
            self::BASE_DELAY_SECONDS * (2 ** ($attempt - 1)),
        );

        if ($error->retryAfterSeconds !== null) {
            $delay = max($delay, (float) $error->retryAfterSeconds);
        }

        return $delay;
    }
}
