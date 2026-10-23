<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Practice;

use App\OrderTesting\Domain\Comparison\ComparisonScore;
use App\OrderTesting\Domain\DemoOrder;

/**
 * One demo order, with what it scored against the original.
 *
 * The score is computed on the way in, never stored: {@see ComparisonScore} is derived from the
 * comparison, and the comparison changes when the original order's items are synced. A stored number
 * would be a stale claim about a comparison that no longer exists.
 */
final readonly class DemoOutcome
{
    public function __construct(
        public DemoOrder $demo,
        public ComparisonScore $score,
    ) {}

    /**
     * An exact perfect match — not a number that merely prints as 100.
     *
     * `displayPercent()` rounds, so 99.6 shows as "100". Treating that as perfect would tell a
     * trainee they had finished when a field still differs, which is the one thing the summary must
     * not do. The tolerance absorbs binary floating-point error in the weighted sum and nothing else.
     */
    public function isPerfect(): bool
    {
        return $this->score->overall !== null && $this->score->overall >= 100.0 - 0.000001;
    }
}
