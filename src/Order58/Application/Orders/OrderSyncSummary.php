<?php

declare(strict_types=1);

namespace App\Order58\Application\Orders;

/**
 * What a sync actually did — the numbers the page prints.
 *
 * `received` counts rows the API returned; the other four account for every one of them, so
 * `created + updated + unchanged + failed === received` always holds. A summary that does not add up is
 * how a partial run gets read as a complete one.
 *
 * `stoppedEarly` exists because "finished" and "ran out of time" must never look alike.
 */
final class OrderSyncSummary
{
    public int $received = 0;
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $failed = 0;

    /** True when the deadline ended the run before every order was processed. */
    public bool $stoppedEarly = false;

    /** @var list<string> operator-facing reasons, bounded so one bad page cannot flood the screen */
    public array $errors = [];

    private const MAX_ERRORS = 20;

    public function note(string $error): void
    {
        $this->failed++;

        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = $error;
        }
    }

    public function processed(): int
    {
        return $this->created + $this->updated + $this->unchanged + $this->failed;
    }

    /**
     * Whether this sync both finished and saved everything it was given.
     *
     * Strict on purpose. An earlier version of this method asked only whether every received order had
     * been *accounted for*, which a run with failures satisfies — a failure is an accounting. That makes
     * a method named `isComplete` return true for a sync that saved nothing, and a caller reading it as
     * "succeeded" would then report success for a run that persisted nothing at all.
     *
     * Use {@see isFullyAccountedFor()} for the bookkeeping invariant; this one answers "did it work".
     */
    public function isComplete(): bool
    {
        return !$this->stoppedEarly && $this->failed === 0 && $this->processed() === $this->received;
    }

    /**
     * Whether every received order ended up in exactly one bucket.
     *
     * An invariant rather than a judgement: it holds for a run full of failures and breaks only if the
     * counting itself is wrong. Tested, because a summary that does not add up is how a partial run gets
     * read as a whole one.
     */
    public function isFullyAccountedFor(): bool
    {
        return $this->processed() === $this->received;
    }
}
