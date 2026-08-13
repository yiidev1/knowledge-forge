<?php

declare(strict_types=1);

namespace App\Reports\Domain;

use App\Shared\Application\Time\AppTimeZone;
use DateTimeImmutable;

use function sprintf;

/**
 * The one-click date ranges offered above the From/To fields.
 *
 * Each preset resolves to a pair of local calendar dates that are then fed through the ordinary `from`/`to`
 * query parameters — there is no separate "preset mode". That keeps a single code path: the resulting URL is
 * indistinguishable from a hand-picked range, stays bookmarkable, and needs no JavaScript, which matters
 * under a `script-src 'self'` policy.
 *
 * Every range is computed in {@see AppTimeZone}, because "today" is a business-calendar question, not a UTC
 * one. All arithmetic happens at local **midday**: on a DST spring-forward date local midnight is a time
 * that does not exist, and day arithmetic anchored there lands on the wrong date.
 *
 * Note {@see self::Last30} deliberately reproduces the report's untouched default window, so the default
 * view and this preset are the same range rather than two definitions that could drift apart.
 */
enum ReportDatePreset: string
{
    case Today = 'today';
    case Last7 = 'last_7';
    case ThisWeek = 'this_week';
    case Last30 = 'last_30';
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Last7 => 'Last 7 days',
            self::ThisWeek => 'This week',
            self::Last30 => 'Last 30 days',
            self::ThisMonth => 'This month',
            self::LastMonth => 'Last month',
        };
    }

    /**
     * The inclusive local `Y-m-d` bounds of this preset.
     *
     * "Last 7" and "Last 30" include today, which is why they subtract one less than their name — seven days
     * ending today is today plus the six before it. That matches {@see ReportDateRange::DEFAULT_DAYS}.
     *
     * The week starts on Monday: PHP's `monday this week` is ISO-8601, so a Sunday resolves back to the
     * Monday six days earlier rather than forward into the next week.
     *
     * @return array{0: string, 1: string} from, to
     */
    public function range(AppTimeZone $timeZone, DateTimeImmutable $now): array
    {
        $today = $now->setTimezone($timeZone->zone())->format('Y-m-d');
        $anchor = new DateTimeImmutable($today . ' 12:00:00', $timeZone->zone());

        return match ($this) {
            self::Today => [$today, $today],
            self::Last7 => [$anchor->modify('-6 days')->format('Y-m-d'), $today],
            self::ThisWeek => [$anchor->modify('monday this week')->format('Y-m-d'), $today],
            self::Last30 => [
                $anchor->modify(sprintf('-%d days', ReportDateRange::DEFAULT_DAYS - 1))->format('Y-m-d'),
                $today,
            ],
            self::ThisMonth => [$anchor->modify('first day of this month')->format('Y-m-d'), $today],
            self::LastMonth => [
                $anchor->modify('first day of last month')->format('Y-m-d'),
                $anchor->modify('last day of last month')->format('Y-m-d'),
            ],
        };
    }

    /**
     * Whether this preset resolves to exactly the range currently being shown. Matching on the dates rather
     * than on a URL flag means a hand-typed range that happens to equal a preset still highlights it.
     */
    public function matches(ReportDateRange $range, AppTimeZone $timeZone, DateTimeImmutable $now): bool
    {
        [$from, $to] = $this->range($timeZone, $now);

        return $range->from === $from && $range->to === $to;
    }
}
