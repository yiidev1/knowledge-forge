<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Practice;

use App\OrderTesting\Domain\Comparison\ComparisonScore;
use App\OrderTesting\Domain\DemoOrder;

/**
 * One imported result, as a row in somebody's try history.
 *
 * Every one of these corresponds to a demo order that actually arrived and was attributed. There is
 * no such thing here as a pending try: pressing Practice Order records an attempt, and an attempt
 * that produced nothing has nothing to show — a card for it would be a result that does not exist.
 */
final readonly class PractitionerTry
{
    public function __construct(
        /** Counted from their oldest result. Zero only if the numbering could not place it. */
        public int $number,
        public DemoOrder $demo,
        public ComparisonScore $score,
    ) {}
}
