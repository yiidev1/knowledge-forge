<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Practice;

use App\OrderTesting\Domain\Comparison\ComparisonScore;

/**
 * What one practitioner's row knows about their scores, including what it could not work out.
 *
 * `bestIsExact` is not a detail: a Best computed over part of somebody's history is a different
 * figure wearing the same name, and a reviewer comparing two practitioners would have no way to know
 * one of them was measured differently. So an inexact Best is not reported at all — `best` is null
 * and the flag says why — and the table points at the drawer, where the whole history is paged.
 */
final readonly class PractitionerScores
{
    public function __construct(
        /** Their most recent result's score. Always exact: it is one comparison. */
        public ?ComparisonScore $latest,
        /** The best of everything they produced, or null when the page could not afford to be sure. */
        public ?ComparisonScore $best,
        public bool $bestIsExact,
    ) {}
}
