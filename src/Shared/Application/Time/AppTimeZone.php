<?php

declare(strict_types=1);

namespace App\Shared\Application\Time;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The one configurable application timezone (default `America/New_York`, from `APP_TIMEZONE`). Database storage
 * and the {@see \App\Shared\Domain\Clock\ClockInterface} stay UTC; this seam converts a stored UTC instant to the
 * business/display zone for: user-facing formatting, the daily-sync wall-clock (02:00/03:00), the calendar date
 * used for per-day idempotency, and next-run calculation. Built on a named IANA zone, so EST/EDT (and any future
 * zone) are handled automatically — no hard-coded UTC offset.
 */
final readonly class AppTimeZone
{
    private DateTimeZone $zone;

    public function __construct(string $timezone = 'America/New_York')
    {
        // An invalid id would throw; the config default is always valid. Fall back if the value is empty.
        $this->zone = new DateTimeZone($timezone !== '' ? $timezone : 'America/New_York');
    }

    public function zone(): DateTimeZone
    {
        return $this->zone;
    }

    /** Formats a UTC instant in the application zone, with a timezone abbreviation by default (e.g. "EDT"). */
    public function format(DateTimeImmutable $instant, string $format = 'M j, Y g:i A T'): string
    {
        return $instant->setTimezone($this->zone)->format($format);
    }

    /** The application-zone calendar date (`Y-m-d`) of an instant — the key for per-day scheduling idempotency. */
    public function businessDate(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->zone)->format('Y-m-d');
    }

    /**
     * Whether the daily `hour`:00 wall-clock time in the application zone has already passed today — used by the
     * catch-up-aware scheduler to decide a run is due (DST-safe via the named zone).
     */
    public function isDailyDue(DateTimeImmutable $now, int $hour): bool
    {
        return $now->getTimestamp() >= $this->dailyOccurrenceToday($now, $hour)->getTimestamp();
    }

    /**
     * The next occurrence of `hour`:00 in the application zone at or after now — for "next scheduled sync". If
     * today's time has passed, returns tomorrow's. DST-safe: constructed in the named zone.
     */
    public function nextDailyOccurrence(DateTimeImmutable $now, int $hour): DateTimeImmutable
    {
        $today = $this->dailyOccurrenceToday($now, $hour);
        if ($now->getTimestamp() < $today->getTimestamp()) {
            return $today;
        }

        return $this->dailyOccurrenceOn($now->setTimezone($this->zone)->modify('+1 day')->format('Y-m-d'), $hour);
    }

    private function dailyOccurrenceToday(DateTimeImmutable $now, int $hour): DateTimeImmutable
    {
        return $this->dailyOccurrenceOn($now->setTimezone($this->zone)->format('Y-m-d'), $hour);
    }

    private function dailyOccurrenceOn(string $date, int $hour): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%s %02d:00:00', $date, $hour), $this->zone);
    }
}
