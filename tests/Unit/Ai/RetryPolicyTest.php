<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Contract\Dto\AiErrorDetails;
use App\Ai\OpenAi\Client\RetryPolicy;
use App\Ai\OpenAi\OpenAiHttpProfile;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

/**
 * The retry decision matrix. The two rules that matter most: only transient failures retry, and a
 * possibly-effective failure retries only for idempotent requests.
 */
final class RetryPolicyTest extends Unit
{
    private function policy(int $maxRetries = 3, int $maxBackoff = 60): RetryPolicy
    {
        return new RetryPolicy(new OpenAiHttpProfile('test', 5, 30, $maxRetries, $maxBackoff));
    }

    private function error(bool $transient, bool $sideEffect, ?int $retryAfter = null): AiErrorDetails
    {
        return AiErrorDetails::of('x', 'x', transient: $transient, sideEffectPossible: $sideEffect, retryAfterSeconds: $retryAfter);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $policy = $this->policy(maxRetries: 2);

        assertSame(0.5, $policy->decideDelay(1, $this->error(true, false), true));
        assertSame(1.0, $policy->decideDelay(2, $this->error(true, false), true));
        assertNull($policy->decideDelay(3, $this->error(true, false), true), 'the 3rd retry exceeds max=2');
    }

    public function testNonTransientNeverRetries(): void
    {
        assertNull($this->policy()->decideDelay(1, $this->error(false, false), true));
    }

    public function testRateLimitRetriesRegardlessOfIdempotency(): void
    {
        // 429 is transient with no side effect, so it retries even for a non-idempotent request.
        assertSame(0.5, $this->policy()->decideDelay(1, $this->error(true, false), false));
    }

    public function testPossiblyEffectiveFailureRetriesOnlyWhenIdempotent(): void
    {
        $error = $this->error(true, true); // e.g. a 5xx after the request was sent

        assertSame(0.5, $this->policy()->decideDelay(1, $error, true), 'idempotent: safe to retry');
        assertNull($this->policy()->decideDelay(1, $error, false), 'non-idempotent: must reconcile, not retry');
    }

    public function testExponentialBackoffIsCappedByProfile(): void
    {
        $policy = $this->policy(maxRetries: 10, maxBackoff: 4);

        assertSame(0.5, $policy->decideDelay(1, $this->error(true, false), true));
        assertSame(1.0, $policy->decideDelay(2, $this->error(true, false), true));
        assertSame(2.0, $policy->decideDelay(3, $this->error(true, false), true));
        assertSame(4.0, $policy->decideDelay(4, $this->error(true, false), true));
        assertSame(4.0, $policy->decideDelay(5, $this->error(true, false), true), 'capped at maxBackoff');
    }

    public function testHonoursRetryAfterWhenLongerThanBackoff(): void
    {
        // Retry-After of 10s overrides the small computed backoff.
        assertSame(10.0, $this->policy()->decideDelay(1, $this->error(true, false, 10), true));
    }
}
