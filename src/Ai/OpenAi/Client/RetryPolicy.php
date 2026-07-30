<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Client;

use App\Ai\Contract\Dto\AiErrorDetails;
use App\Ai\OpenAi\OpenAiHttpProfile;

use function max;
use function min;

/**
 * Decides whether and how long to wait before retrying a failed request.
 *
 * Pure and deterministic, so the whole retry matrix can be unit-tested. Two rules matter most:
 *
 * - Only transient failures (429, 5xx, connect errors) are ever retried.
 * - A failure that MIGHT have taken effect on the server ({@see AiErrorDetails::$sideEffectPossible}) is
 *   retried only for an idempotent request. A non-idempotent create that read-times-out is not resent
 *   here — it surfaces to the operation ledger, which reconciles it instead of risking a duplicate.
 *
 * Backoff is exponential and capped by the profile, and never shorter than a `Retry-After` the provider
 * asked for. Jitter is intentionally omitted: there is a single worker and a single chat caller, so
 * there is no fleet to de-synchronise, and determinism makes the policy testable.
 */
final readonly class RetryPolicy
{
    private const BASE_DELAY_SECONDS = 0.5;

    public function __construct(
        private OpenAiHttpProfile $profile,
    ) {}

    /**
     * @param int  $attempt    1-based count of failures so far (1 = the first failure).
     * @param bool $idempotent Whether resending the request is safe.
     *
     * @return float|null Seconds to wait before retrying, or null to give up.
     */
    public function decideDelay(int $attempt, AiErrorDetails $error, bool $idempotent): ?float
    {
        if ($attempt > $this->profile->maxRetries) {
            return null;
        }

        if (!$error->transient) {
            return null;
        }

        // A possibly-effective failure on a non-idempotent request must not be blindly resent.
        if ($error->sideEffectPossible && !$idempotent) {
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
