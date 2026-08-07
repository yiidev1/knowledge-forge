<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Time\AppTimeZone;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertSame;

/**
 * The application-timezone seam: UTC instants are converted to the configured zone for display, the business
 * calendar date, the daily-due check and next-run — all DST-safe via the named IANA zone (never a fixed offset).
 * Database storage stays UTC; this class only interprets stored instants.
 */
final class AppTimeZoneTest extends Unit
{
    private AppTimeZone $ny;

    protected function _before(): void
    {
        $this->ny = new AppTimeZone('America/New_York');
    }

    public function testFormatShowsTheCorrectAbbreviationAcrossDst(): void
    {
        // 07:00 UTC in July → 03:00 EDT (UTC-4); 08:00 UTC in January → 03:00 EST (UTC-5). Same 3 AM local.
        assertSame('Jul 15, 2026 3:00 AM EDT', $this->ny->format($this->utc('2026-07-15 07:00:00')));
        assertSame('Jan 15, 2026 3:00 AM EST', $this->ny->format($this->utc('2026-01-15 08:00:00')));
    }

    public function testBusinessDateUsesTheApplicationZoneNotUtc(): void
    {
        // 03:30 UTC on Mar 8 is still Mar 7 in New York (23:30 EST) — the calendar date must follow NY.
        assertSame('2026-03-07', $this->ny->businessDate($this->utc('2026-03-08 03:30:00')));
        // Same instant is 2026-03-08 in UTC — proving we did not just read the UTC date.
        assertSame('2026-03-08', $this->utc('2026-03-08 03:30:00')->format('Y-m-d'));
    }

    public function testIsDailyDueComparesAgainstTheLocalWallClock(): void
    {
        // 2026-07-15: 03:00 EDT == 07:00 UTC. Before it → not due; at/after → due.
        assertSame(false, $this->ny->isDailyDue($this->utc('2026-07-15 06:59:00'), 3));
        assertSame(true, $this->ny->isDailyDue($this->utc('2026-07-15 07:00:00'), 3));
        assertSame(true, $this->ny->isDailyDue($this->utc('2026-07-15 12:00:00'), 3));
    }

    public function testNextDailyOccurrenceIsDstAwareNotAFixedOffset(): void
    {
        // Winter: next 03:00 NY (EST, UTC-5) after 09:00 UTC is the following day at 08:00 UTC.
        $winter = $this->ny->nextDailyOccurrence($this->utc('2026-01-15 09:00:00'), 3);
        assertSame('2026-01-16 08:00:00', $winter->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

        // Summer: 03:00 NY is EDT (UTC-4) → 07:00 UTC — a DIFFERENT UTC offset, proving no hard-coded offset.
        $summer = $this->ny->nextDailyOccurrence($this->utc('2026-07-15 09:00:00'), 3);
        assertSame('2026-07-16 07:00:00', $summer->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

        // Today's occurrence when it is still ahead of now (00:30 UTC = 20:30 previous day EST → 03:00 today).
        $laterToday = $this->ny->nextDailyOccurrence($this->utc('2026-07-15 06:00:00'), 3);
        assertSame('2026-07-15 07:00:00', $laterToday->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    private function utc(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }
}
