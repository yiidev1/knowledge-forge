<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Practice;

use App\OrderTesting\Domain\Comparison\ComparisonScore;

use function count;

/**
 * What one practitioner's row knows about their scores, including what it could not work out.
 *
 * `bestIsExact` is not a detail: a Best computed over part of somebody's history is a different
 * figure wearing the same name, and a reviewer comparing two practitioners would have no way to know
 * one of them was measured differently. So an inexact Best is not reported at all — `best` is null
 * and the flag says why — and the table points at the drawer, where the whole history is paged.
 *
 * `tries` follows the same rule. It is every try the row could afford to score, oldest first — all of
 * them whenever `bestIsExact`, and otherwise only the latest. The table says how many earlier tries it
 * is not showing rather than letting a short list pass for the whole history.
 */
final readonly class PractitionerScores
{
    /**
     * @param list<PractitionerTry> $tries oldest first, numbered over the practitioner's whole history
     */
    public function __construct(
        /** Their most recent result's score. Always exact: it is one comparison. */
        public ?ComparisonScore $latest,
        /** The best of everything they produced, or null when the page could not afford to be sure. */
        public ?ComparisonScore $best,
        public bool $bestIsExact,
        public array $tries = [],
    ) {}

    /** The newest try — the one the row's own View Comparison opens. */
    public function latestTry(): ?PractitionerTry
    {
        return $this->tries === [] ? null : $this->tries[count($this->tries) - 1];
    }

    /** How many of their tries are older than the first one listed, and so not on this row. */
    public function earlierTriesNotListed(int $completedTries): int
    {
        return $completedTries > count($this->tries) ? $completedTries - count($this->tries) : 0;
    }
}
