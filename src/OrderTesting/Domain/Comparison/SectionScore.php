<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

use function max;
use function min;
use function round;

/**
 * What one section of a comparison scored, and how much it stood on.
 *
 * ## The coverage is part of the answer
 *
 * A percentage without its denominator is not explainable: a section can read 100% because every one
 * of seven fields agreed, or because the only field anybody filled in agreed. Both happen in this data
 * — one live demo order scores Customer 100% from a single comparable field — so `scoredUnits` and
 * `visibleUnits` travel with the number and the page prints them underneath it.
 *
 * ## Scored, informational, and not applicable are three different things
 *
 * `percent === null` with `informationalOnly` means the section is shown and never scored: Delivery
 * address, whose street line is redacted out of both documents by policy. `percent === null` without
 * it means nothing in the section could be scored at all, which the page shows as N/A rather than as a
 * zero somebody might read as a failure.
 */
final readonly class SectionScore
{
    /**
     * @param string   $title            the section heading this belongs to
     * @param float|null $percent         0–100 at full precision, or null when not scored
     * @param int      $matched          agreed, with a value on both sides
     * @param int      $different        both sides hold a value and they disagree
     * @param int      $missing          the original holds a value the demo does not
     * @param int      $extra            the demo holds a value the original does not
     * @param int      $notApplicable    shown but not scorable — uncomparable by the engine
     * @param int      $scoredUnits      how many units are behind `percent`
     * @param int      $visibleUnits     how many units the page shows for this section
     * @param int      $hiddenAbsences   fields absent on BOTH sides, which the page does not render
     * @param bool     $extraIsScored    whether `extra` counted against the score (items yes, headers no)
     * @param string   $unit             'field' or 'item', for the coverage line's wording
     * @param float    $weight           this section's share of the whole, as a percentage; 0 when it has none
     */
    public function __construct(
        public string $title,
        public ?float $percent,
        public int $matched,
        public int $different,
        public int $missing,
        public int $extra,
        public int $notApplicable,
        public int $scoredUnits,
        public int $visibleUnits,
        public int $hiddenAbsences = 0,
        public bool $extraIsScored = false,
        public string $unit = 'field',
        public bool $informationalOnly = false,
        /**
         * Carried rather than looked up, so the page can show how the overall was made without
         * holding a second copy of the weighting, and so the figure shown is the figure summed. One
         * table of weights, in {@see \App\OrderTesting\Application\Comparison\ComparisonScorer}.
         *
         * A share of a hundred rather than a tally out of 180: the same proportions in the units a
         * reader was told about. Float, because a third of a hundred is not an integer.
         */
        public float $weight = 0.0,
    ) {}

    public function isScored(): bool
    {
        return $this->percent !== null;
    }

    /**
     * The whole percent to print, which never claims more than the number earned.
     *
     * Half-up, except at the two ends: a score that is not quite everything must not print 100, and
     * one that is not quite nothing must not print 0. 99.6% is a different statement from 100%, and on
     * a page about accuracy the difference is the point.
     */
    public function displayPercent(): ?int
    {
        return $this->percent === null ? null : self::display($this->percent);
    }

    public static function display(float $percent): int
    {
        $rounded = (int) round($percent);

        if ($percent > 0.0 && $rounded === 0) {
            return 1;
        }

        if ($percent < 100.0 && $rounded === 100) {
            return 99;
        }

        return max(0, min(100, $rounded));
    }

    /**
     * Whether the number rests on less than half of what the reader can see.
     *
     * Not a judgement about the demo order — the score is what it is. It is a judgement about the
     * score, and the page says so by drawing the coverage line differently rather than by moving the
     * percentage, which would be inventing a different measurement.
     */
    public function hasLowCoverage(): bool
    {
        return $this->isScored() && $this->visibleUnits > 0
            && $this->scoredUnits * 2 < $this->visibleUnits;
    }
}
