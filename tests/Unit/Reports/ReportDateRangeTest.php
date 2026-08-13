<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports;

use App\Reports\Domain\ReportDateRange;
use App\Shared\Application\Time\AppTimeZone;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Local calendar dates in, half-open UTC window out.
 *
 * The interesting cases are the ones where the two zones disagree: a local day starts four or five hours
 * into the UTC day depending on daylight saving, and a range that ignored that would quietly include or drop
 * several hours of activity at each end.
 */
final class ReportDateRangeTest extends Unit
{
    private AppTimeZone $timeZone;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->timeZone = new AppTimeZone('America/New_York');
        $this->now = new DateTimeImmutable('2026-05-20 12:00:00', new DateTimeZone('UTC'));
    }

    public function testDefaultsToThirtyInclusiveLocalDays(): void
    {
        $range = ReportDateRange::fromRequest(null, null, $this->timeZone, $this->now);

        assertSame('2026-04-21', $range->from);
        assertSame('2026-05-20', $range->to);
        assertSame(30, $range->days());
        assertFalse($range->wasAdjusted);
    }

    /** EDT is UTC-4: the local day begins at 04:00 UTC. */
    public function testSummerRangeConvertsWithDaylightOffset(): void
    {
        $range = ReportDateRange::fromRequest('2026-05-10', '2026-05-10', $this->timeZone, $this->now);

        assertSame('2026-05-10 04:00:00', $range->startUtc->format('Y-m-d H:i:s'));
        assertSame('2026-05-11 04:00:00', $range->endUtcExclusive->format('Y-m-d H:i:s'));
    }

    /** EST is UTC-5: the same calendar arithmetic must produce a different UTC instant. */
    public function testWinterRangeConvertsWithStandardOffset(): void
    {
        $range = ReportDateRange::fromRequest('2026-01-10', '2026-01-10', $this->timeZone, $this->now);

        assertSame('2026-01-10 05:00:00', $range->startUtc->format('Y-m-d H:i:s'));
        assertSame('2026-01-11 05:00:00', $range->endUtcExclusive->format('Y-m-d H:i:s'));
    }

    /**
     * A range spanning the spring-forward date. Day arithmetic done at local midnight would land on a time
     * that does not exist that morning; this asserts the range still covers exactly the days requested.
     */
    public function testRangeAcrossTheDaylightSavingSwitchKeepsItsDayCount(): void
    {
        // In 2026 US DST begins on 8 March.
        $range = ReportDateRange::fromRequest('2026-03-07', '2026-03-09', $this->timeZone, $this->now);

        assertSame('2026-03-07', $range->from);
        assertSame('2026-03-09', $range->to);
        assertSame('2026-03-07 05:00:00', $range->startUtc->format('Y-m-d H:i:s'));
        // The end boundary is on the far side of the switch, so it is an hour earlier in UTC terms.
        assertSame('2026-03-10 04:00:00', $range->endUtcExclusive->format('Y-m-d H:i:s'));
    }

    public function testMalformedDatesFallBackAndAreFlagged(): void
    {
        $range = ReportDateRange::fromRequest('not-a-date', '', $this->timeZone, $this->now);

        assertTrue($range->wasAdjusted);
        assertSame(30, $range->days());
    }

    /** `createFromFormat` alone would roll 31 February into March — the round-trip check rejects it. */
    public function testImpossibleCalendarDateIsRejected(): void
    {
        $range = ReportDateRange::fromRequest('2026-02-31', '2026-03-05', $this->timeZone, $this->now);

        assertTrue($range->wasAdjusted);
    }

    public function testReversedRangeIsSwappedRatherThanEmptied(): void
    {
        $range = ReportDateRange::fromRequest('2026-05-20', '2026-05-10', $this->timeZone, $this->now);

        assertSame('2026-05-10', $range->from);
        assertSame('2026-05-20', $range->to);
        assertFalse($range->wasAdjusted);
    }

    public function testOverlyWideRangeIsCappedAndFlagged(): void
    {
        $range = ReportDateRange::fromRequest('2020-01-01', '2026-05-20', $this->timeZone, $this->now);

        assertTrue($range->wasAdjusted);
        assertSame(ReportDateRange::MAX_DAYS, $range->days());
        assertSame('2026-05-20', $range->to);
    }

    public function testSingleDayRangeIsOneDayNotZero(): void
    {
        $range = ReportDateRange::fromRequest('2026-05-10', '2026-05-10', $this->timeZone, $this->now);

        assertSame(1, $range->days());
    }
}
