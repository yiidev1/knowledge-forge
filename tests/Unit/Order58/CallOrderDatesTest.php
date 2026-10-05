<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\Orders\CallOrderDates;
use Codeception\Test\Unit;

/**
 * Which Order58 business day a call belongs to.
 *
 * ## What is being protected
 *
 * The recording provider publishes `callTime` in UTC; Order58 groups orders by the store's own New York
 * day. Asking the Orders API for the wrong day returns a perfectly valid response that simply does not
 * contain the order — a failure that looks exactly like "this order does not exist", which is the worst
 * shape a bug can take.
 *
 * The first case is the live one, verified against both APIs: call 22733319 at 2026-10-05 00:10:07 UTC
 * produced order 16754561, which the Orders API returns for 2026-10-04 and not for 2026-10-05.
 */
final class CallOrderDatesTest extends Unit
{
    /** **The verified case.** A UTC timestamp just after midnight is the previous New York day. */
    public function testAMidnightUtcCallBelongsToThePreviousNewYorkDay(): void
    {
        $this->assertSame('2026-10-04', CallOrderDates::businessDate('2026-10-05 00:10:07'));
    }

    /** And a daytime call is the same day in both, which is why the bug was not obvious. */
    public function testADaytimeCallKeepsItsDate(): void
    {
        $this->assertSame('2026-10-05', CallOrderDates::businessDate('2026-10-05 15:00:00'));
    }

    /** The whole timestamp is read, never the leading date. That shortcut IS the bug. */
    public function testTheLeadingDateIsNotUsedAsTheAnswer(): void
    {
        // Same leading date, four hours apart, two different business days.
        $this->assertSame('2026-10-04', CallOrderDates::businessDate('2026-10-05 03:59:59'));
        $this->assertSame('2026-10-05', CallOrderDates::businessDate('2026-10-05 04:00:00'));
    }

    /** One evening's calls are one day's orders, so they cost one request. */
    public function testRepeatedDatesCollapseToOne(): void
    {
        $this->assertSame(['2026-10-04'], CallOrderDates::fromCallTimes([
            '2026-10-05 00:10:07',
            '2026-10-05 01:22:00',
            '2026-10-04 23:00:00',
        ]));
    }

    /** A selection spanning the boundary needs both days — the case that makes dedup insufficient. */
    public function testASelectionSpanningMidnightNeedsTwoDates(): void
    {
        $this->assertSame(
            ['2026-10-04', '2026-10-05'],
            CallOrderDates::fromCallTimes([
                '2026-10-05 15:00:00',  // NY 2026-10-05 11:00
                '2026-10-05 00:10:07',  // NY 2026-10-04 20:10
            ]),
        );
    }

    /** Sorted, so a caller processes them predictably and a test can assert the list. */
    public function testDatesComeBackOldestFirst(): void
    {
        $this->assertSame(
            ['2026-10-03', '2026-10-04', '2026-10-05'],
            CallOrderDates::fromCallTimes([
                // Deliberately out of order, and deliberately a mix of before- and after-midnight UTC.
                '2026-10-06 00:30:00',  // NY 2026-10-05 20:30
                '2026-10-04 16:00:00',  // NY 2026-10-04 12:00
                '2026-10-03 18:00:00',  // NY 2026-10-03 14:00
            ]),
        );
    }

    /**
     * DST, handled by the identifier rather than by arithmetic.
     *
     * Spring forward 2026-03-08 and back 2026-11-01. A fixed -5 would put the first of these on the
     * wrong day; a fixed -4 would do the same to the second. Neither offset is right all year, which is
     * the whole reason the zone is named rather than numeric.
     */
    public function testDaylightSavingIsHandledByTheZone(): void
    {
        // EDT (-4): 03:30 UTC is 23:30 the previous day.
        $this->assertSame('2026-03-08', CallOrderDates::businessDate('2026-03-09 03:30:00'));
        // EST (-5): the same clock time in November is still the previous day, one hour further back.
        $this->assertSame('2026-11-01', CallOrderDates::businessDate('2026-11-02 04:30:00'));
        // And the hour that exists in one and not the other resolves without throwing.
        $this->assertSame('2026-03-08', CallOrderDates::businessDate('2026-03-08 07:30:00'));
    }

    /**
     * An unreadable timestamp is dropped, never replaced.
     *
     * Substituting today, or the page's date, would produce a confident request for the wrong day — and
     * the Orders API would answer it successfully with somebody else's orders.
     */
    public function testAnUnreadableTimestampIsDroppedRatherThanGuessed(): void
    {
        $this->assertNull(CallOrderDates::businessDate(''));
        $this->assertNull(CallOrderDates::businessDate('not a timestamp'));
        $this->assertSame([], CallOrderDates::fromCallTimes(['', 'rubbish']));
        // And it does not take the usable ones down with it.
        $this->assertSame(['2026-10-04'], CallOrderDates::fromCallTimes(['rubbish', '2026-10-05 00:10:07']));
    }
}
