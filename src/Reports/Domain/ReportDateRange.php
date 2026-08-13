<?php

declare(strict_types=1);

namespace App\Reports\Domain;

use App\Shared\Application\Time\AppTimeZone;
use DateTimeImmutable;
use DateTimeZone;

use function max;
use function sprintf;

/**
 * An inclusive local calendar range, resolved to the half-open UTC window a query can use.
 *
 * The two zones are the whole point. An administrator picks calendar dates in the business zone
 * (`APP_TIMEZONE`), but every stored timestamp is UTC — so "1 Aug to 13 Aug" has to become
 * `created_at >= <local 1 Aug 00:00 as UTC> AND created_at < <local 14 Aug 00:00 as UTC>`. Half-open on
 * purpose: an inclusive upper bound would need a 23:59:59 that silently drops the final second of the day.
 *
 * The conversion is done here, in PHP, and never in SQL — the database session is UTC by invariant, so a
 * `CONVERT_TZ` in a query would reintroduce a second, disagreeing notion of "today".
 */
final readonly class ReportDateRange
{
    /** Default window: today plus the previous 29 local days — 30 calendar days inclusive. */
    public const DEFAULT_DAYS = 30;

    /** Widest range the report will run, to keep an accidental multi-year scan from hitting the database. */
    public const MAX_DAYS = 366;

    private function __construct(
        /** Local calendar date, `Y-m-d`, inclusive. */
        public string $from,
        /** Local calendar date, `Y-m-d`, inclusive. */
        public string $to,
        public DateTimeImmutable $startUtc,
        /** Exclusive: local midnight of the day AFTER {@see self::$to}. */
        public DateTimeImmutable $endUtcExclusive,
        /** True when the submitted dates were unusable or too wide and the default was substituted. */
        public bool $wasAdjusted,
    ) {}

    /**
     * Builds a range from raw query input. Never throws: unusable input silently falls back to the default
     * window and raises {@see self::$wasAdjusted} so the page can say so.
     */
    public static function fromRequest(
        ?string $from,
        ?string $to,
        AppTimeZone $timeZone,
        DateTimeImmutable $now,
    ): self {
        $today = $now->setTimezone($timeZone->zone())->format('Y-m-d');
        $defaultFrom = self::shiftDays($today, -(self::DEFAULT_DAYS - 1), $timeZone);

        $parsedFrom = self::parseDate($from, $timeZone);
        $parsedTo = self::parseDate($to, $timeZone);
        $adjusted = ($from !== null && $from !== '' && $parsedFrom === null)
            || ($to !== null && $to !== '' && $parsedTo === null);

        $fromDate = $parsedFrom ?? $defaultFrom;
        $toDate = $parsedTo ?? $today;

        // A reversed range is a slip, not an error — swap it rather than showing an empty report.
        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        if (self::daysBetween($fromDate, $toDate, $timeZone) > self::MAX_DAYS) {
            $fromDate = self::shiftDays($toDate, -(self::MAX_DAYS - 1), $timeZone);
            $adjusted = true;
        }

        return new self(
            from: $fromDate,
            to: $toDate,
            startUtc: self::localMidnight($fromDate, $timeZone)->setTimezone(new DateTimeZone('UTC')),
            // The day AFTER `to`, so the whole of the final local day is inside the window.
            endUtcExclusive: self::localMidnight(self::shiftDays($toDate, 1, $timeZone), $timeZone)
                ->setTimezone(new DateTimeZone('UTC')),
            wasAdjusted: $adjusted,
        );
    }

    public function days(): int
    {
        return (int) $this->startUtc->diff($this->endUtcExclusive)->days;
    }

    /**
     * Strict `Y-m-d`. `createFromFormat` alone would accept "2026-02-31" and roll it into March, so the
     * round-trip check rejects anything that did not survive parsing unchanged.
     */
    private static function parseDate(?string $value, AppTimeZone $timeZone): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timeZone->zone());
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    /**
     * Day arithmetic done at local midday, not midnight: on a DST spring-forward date local midnight can be
     * a time that does not exist, and "+1 day" from it lands on the wrong date.
     */
    private static function shiftDays(string $date, int $days, AppTimeZone $timeZone): string
    {
        return (new DateTimeImmutable($date . ' 12:00:00', $timeZone->zone()))
            ->modify(sprintf('%+d days', $days))
            ->format('Y-m-d');
    }

    private static function daysBetween(string $from, string $to, AppTimeZone $timeZone): int
    {
        $start = new DateTimeImmutable($from . ' 12:00:00', $timeZone->zone());
        $end = new DateTimeImmutable($to . ' 12:00:00', $timeZone->zone());

        return max(0, (int) $start->diff($end)->days) + 1;
    }

    private static function localMidnight(string $date, AppTimeZone $timeZone): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', $timeZone->zone());
    }
}
