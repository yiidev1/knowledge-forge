<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Contract\ActiveFlag;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertTrue;

/**
 * The source-active flag is the only signal for whether a store is active, so its normalization must be
 * total and explicit: every recognised representation maps to a definite boolean, and anything unrecognised
 * maps to null ("unknown") rather than being silently treated as inactive.
 */
final class ActiveFlagTest extends Unit
{
    public function testNormalizesIntegerOneAndZero(): void
    {
        assertTrue(ActiveFlag::normalize(1));
        assertFalse(ActiveFlag::normalize(0));
    }

    public function testNormalizesNumericStrings(): void
    {
        assertTrue(ActiveFlag::normalize('1'));
        assertFalse(ActiveFlag::normalize('0'));
        assertTrue(ActiveFlag::normalize(' 1 '));
        assertFalse(ActiveFlag::normalize(' 0 '));
    }

    public function testNormalizesBooleans(): void
    {
        assertTrue(ActiveFlag::normalize(true));
        assertFalse(ActiveFlag::normalize(false));
    }

    public function testMissingOrInvalidValuesAreNullNotFalse(): void
    {
        assertNull(ActiveFlag::normalize(null));
        assertNull(ActiveFlag::normalize(''));
        assertNull(ActiveFlag::normalize('yes'));
        assertNull(ActiveFlag::normalize('true'));
        assertNull(ActiveFlag::normalize(2));
        assertNull(ActiveFlag::normalize(-1));
        assertNull(ActiveFlag::normalize(1.0));
        assertNull(ActiveFlag::normalize([]));
    }
}
