<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Practice;

use App\OrderTesting\Domain\TestAttempt;

use function count;

/**
 * One Try: one authenticated Practice Order click, and whatever came of it.
 *
 * ## A try is an attempt, not a demo order
 *
 * Numbering follows the **attempts**, because an attempt is the thing a person did: they pressed the
 * button at a known moment, as a known identity. A demo order is only evidence that something came
 * back. One attempt may produce none, one, or several — so `outcomes` is a list, and every demo order
 * is kept rather than the newest replacing the rest.
 *
 * Numbering from attempts is also what makes it stable: a file imported late attaches to the attempt
 * it was created during, and the try numbers around it do not move.
 */
final readonly class PracticeTry
{
    /**
     * @param list<DemoOutcome> $outcomes newest-first, as the repository returns them
     */
    public function __construct(
        public int $number,
        public ?TestAttempt $attempt,
        public array $outcomes,
    ) {}

    /** An attempt that has produced nothing yet: pressed, but no demo order imported. */
    public function isPending(): bool
    {
        return $this->attempt !== null && $this->outcomes === [];
    }

    /** A demo order nobody can be shown to have made. Its own kind of row, never somebody's try. */
    public function isUnmatched(): bool
    {
        return $this->attempt === null;
    }

    /** The result this try is judged on: its best, because a trainee's best is what they achieved. */
    public function best(): ?DemoOutcome
    {
        $best = null;

        foreach ($this->outcomes as $outcome) {
            if ($outcome->score->overall === null) {
                continue;
            }

            if ($best === null || $outcome->score->overall > ($best->score->overall ?? -1.0)) {
                $best = $outcome;
            }
        }

        // Every outcome scoreless: still show one, so the card can say why rather than look empty.
        return $best ?? ($this->outcomes[0] ?? null);
    }

    public function demoCount(): int
    {
        return count($this->outcomes);
    }
}
