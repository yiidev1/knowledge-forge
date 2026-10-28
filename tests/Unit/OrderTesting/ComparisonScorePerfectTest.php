<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\OrderTesting\Domain\Comparison\ComparisonScore;
use Codeception\Test\Unit;

/**
 * "View Comparison" is offered only for a perfect score, decided on the number rather than its print.
 */
final class ComparisonScorePerfectTest extends Unit
{
    public function testOnlyAFullHundredIsPerfect(): void
    {
        self::assertTrue((new ComparisonScore(100.0, null, []))->isPerfect());
        self::assertFalse((new ComparisonScore(99.6, null, []))->isPerfect(), 'prints as 99, not 100');
        self::assertFalse((new ComparisonScore(99.999999, null, []))->isPerfect());
        self::assertFalse((new ComparisonScore(47.0, null, []))->isPerfect());
        self::assertFalse((new ComparisonScore(0.0, null, []))->isPerfect());
    }

    /** A comparison that could not be made is not a perfect one. */
    public function testAnUnavailableScoreIsNotPerfect(): void
    {
        self::assertFalse((new ComparisonScore(null, 'source order not mirrored', []))->isPerfect());
    }

    /** The link and the printed figure agree: perfect exactly when it prints 100%. */
    public function testPerfectMatchesWhatThePagePrints(): void
    {
        foreach ([100.0, 99.99, 99.5, 50.0] as $value) {
            $score = new ComparisonScore($value, null, []);

            self::assertSame($score->displayPercent() === 100, $score->isPerfect(), (string) $value);
        }
    }
}
