<?php

declare(strict_types=1);

namespace App\OrderTesting\Application;

use App\OrderTesting\Domain\TestAttempt;

/**
 * What asking to start a test produced.
 *
 * Three outcomes, because the Demo URL button has three honest answers: go, carry on with the one you
 * already have, and somebody else is already doing this.
 */
final readonly class StartAttemptOutcome
{
    private function __construct(
        public ?TestAttempt $attempt,
        public bool $started,
        /** Set only when the request was refused. Operator-facing. */
        public ?string $refusal = null,
    ) {}

    /** A new attempt; the operator goes to Order58. */
    public static function started(TestAttempt $attempt): self
    {
        return new self($attempt, true);
    }

    /**
     * The same operator already holds this one.
     *
     * Not an error and not a second row: they clicked twice, or came back to a tab. They go to Order58
     * exactly as before, and the demo orders they create still credit the attempt they already have.
     */
    public static function resumed(TestAttempt $attempt): self
    {
        return new self($attempt, false);
    }

    /** Somebody else holds it. Nothing is created and the operator is told who and until when. */
    public static function refused(string $reason, TestAttempt $holder): self
    {
        return new self($holder, false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->refusal === null;
    }
}
