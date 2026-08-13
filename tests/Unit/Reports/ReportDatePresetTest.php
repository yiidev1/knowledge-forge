<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports;

use App\Reports\Domain\ReportDatePreset;
use App\Reports\Domain\ReportDateRange;
use App\Shared\Application\Time\AppTimeZone;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Preset ranges resolve on the business calendar, not the UTC one.
 *
 * The cases that matter are the ones where a naive implementation drifts: a UTC instant that is already
 * "tomorrow" in UTC but still today locally, a Sunday (which ISO puts at the *end* of its week, not the
 * start), month lengths that differ, and a year boundary.
 */
final class ReportDatePresetTest extends Unit
{
    private AppTimeZone $timeZone;

    protected function _before(): void
    {
        $this->timeZone = new AppTimeZone('America/New_York');
    }

    public function testTodayIsASingleLocalDay(): void
    {
        assertSame(
            ['2026-08-13', '2026-08-13'],
            ReportDatePreset::Today->range($this->timeZone, $this->at('2026-08-13 16:00:00')),
        );
    }

    /**
     * 02:00 UTC on the 14th is still 22:00 on the 13th in New York. "Today" must follow the business
     * calendar, otherwise the report would jump a day at 8pm local.
     */
    public function testTodayFollowsTheLocalCalendarNotUtc(): void
    {
        assertSame(
            ['2026-08-13', '2026-08-13'],
            ReportDatePreset::Today->range($this->timeZone, $this->at('2026-08-14 02:00:00')),
        );
    }

    public function testLastSevenAndThirtyDaysIncludeToday(): void
    {
        $now = $this->at('2026-08-13 16:00:00');

        // Seven days ending today is today plus the six before it.
        assertSame(['2026-08-07', '2026-08-13'], ReportDatePreset::Last7->range($this->timeZone, $now));
        assertSame(['2026-07-15', '2026-08-13'], ReportDatePreset::Last30->range($this->timeZone, $now));
    }

    /** The default view and the Last 30 Days preset must be the same window, not two definitions. */
    public function testLastThirtyDaysMatchesTheUntouchedDefaultRange(): void
    {
        $now = $this->at('2026-08-13 16:00:00');
        $default = ReportDateRange::fromRequest(null, null, $this->timeZone, $now);

        assertSame([$default->from, $default->to], ReportDatePreset::Last30->range($this->timeZone, $now));
        assertTrue(ReportDatePreset::Last30->matches($default, $this->timeZone, $now));
    }

    public function testThisWeekStartsOnMonday(): void
    {
        // Thursday.
        assertSame(
            ['2026-08-10', '2026-08-13'],
            ReportDatePreset::ThisWeek->range($this->timeZone, $this->at('2026-08-13 16:00:00')),
        );
    }

    /** ISO puts Sunday at the end of its week, so it resolves back six days, never forward. */
    public function testSundayBelongsToTheWeekThatAlreadyStarted(): void
    {
        assertSame(
            ['2026-08-10', '2026-08-16'],
            ReportDatePreset::ThisWeek->range($this->timeZone, $this->at('2026-08-16 16:00:00')),
        );
    }

    public function testMondayIsASingleDayWeekSoFar(): void
    {
        assertSame(
            ['2026-08-10', '2026-08-10'],
            ReportDatePreset::ThisWeek->range($this->timeZone, $this->at('2026-08-10 16:00:00')),
        );
    }

    public function testThisMonthRunsFromTheFirstToToday(): void
    {
        assertSame(
            ['2026-08-01', '2026-08-13'],
            ReportDatePreset::ThisMonth->range($this->timeZone, $this->at('2026-08-13 16:00:00')),
        );
    }

    public function testLastMonthIsTheWholePreviousMonth(): void
    {
        assertSame(
            ['2026-07-01', '2026-07-31'],
            ReportDatePreset::LastMonth->range($this->timeZone, $this->at('2026-08-13 16:00:00')),
        );
    }

    /** From a 31-day month into a 30-day one — the naive "-1 month" bug lands on the 31st and rolls over. */
    public function testLastMonthHandlesAShorterPreviousMonth(): void
    {
        assertSame(
            ['2026-06-01', '2026-06-30'],
            ReportDatePreset::LastMonth->range($this->timeZone, $this->at('2026-07-31 16:00:00')),
        );
    }

    public function testLastMonthCrossesTheYearBoundary(): void
    {
        assertSame(
            ['2025-12-01', '2025-12-31'],
            ReportDatePreset::LastMonth->range($this->timeZone, $this->at('2026-01-15 16:00:00')),
        );
    }

    public function testFebruaryIsResolvedByLength(): void
    {
        // 2026 is not a leap year.
        assertSame(
            ['2026-02-01', '2026-02-28'],
            ReportDatePreset::LastMonth->range($this->timeZone, $this->at('2026-03-10 16:00:00')),
        );
    }

    /**
     * Day arithmetic runs at local midday precisely so a spring-forward date cannot shift it: local midnight
     * does not exist that morning.
     */
    public function testRangesSurviveTheDaylightSavingSwitch(): void
    {
        // US DST begins 8 March 2026.
        assertSame(
            ['2026-03-02', '2026-03-08'],
            ReportDatePreset::Last7->range($this->timeZone, $this->at('2026-03-08 18:00:00')),
        );
        assertSame(
            ['2026-03-02', '2026-03-08'],
            ReportDatePreset::ThisWeek->range($this->timeZone, $this->at('2026-03-08 18:00:00')),
        );
    }

    public function testMatchesOnlyTheRangeCurrentlyShown(): void
    {
        $now = $this->at('2026-08-13 16:00:00');
        $today = ReportDateRange::fromRequest('2026-08-13', '2026-08-13', $this->timeZone, $now);

        assertTrue(ReportDatePreset::Today->matches($today, $this->timeZone, $now));
        assertFalse(ReportDatePreset::Last7->matches($today, $this->timeZone, $now));
        assertFalse(ReportDatePreset::ThisMonth->matches($today, $this->timeZone, $now));
    }

    public function testEveryPresetHasALabel(): void
    {
        foreach (ReportDatePreset::cases() as $preset) {
            assertTrue($preset->label() !== '');
        }
    }

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }
}
