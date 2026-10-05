<?php

declare(strict_types=1);

namespace App\Order58\Application\Orders;

use DateTimeImmutable;
use DateTimeZone;

use function array_keys;
use function sort;

/**
 * Which Order58 business days a set of calls belongs to.
 *
 * ## The fact this exists to apply
 *
 * The recording provider publishes `callTime` in **UTC**. Order58 groups orders by the store's own
 * **America/New_York** calendar day. Those are different days for every call placed after 20:00 in New
 * York, which on a restaurant's line is the middle of dinner service.
 *
 * Verified against the live APIs rather than assumed. Call `22733319` carries
 * `callTime = 2026-10-05 00:10:07`; the order it produced, `16754561`, was created at
 * `2026-10-05 00:10:40 UTC` = `2026-10-04 20:10:40` in New York, and the Orders API returns it for
 * `date_from=date_to=2026-10-04` and not for `2026-10-05`. The call precedes its own order by 33
 * seconds in UTC, which is the right way round; read as New York it would precede it by four hours.
 *
 * The spill is routine, not an edge case. Measured over two consecutive days for store 1731: the New
 * York day 2026-10-03 held 28 orders, four of which carry a UTC date of 2026-10-04; 2026-10-04 held 34,
 * two of them dated 2026-10-05 in UTC.
 *
 * ## Why the page's own date is never used
 *
 * That date filters the call list, and it filters it on the provider's UTC string. Passing it to the
 * Orders API asks for the wrong day for exactly the calls described above — the evening ones, which are
 * most of them. The date has to come from each call's own timestamp.
 *
 * ## Timezone identifiers, never offsets
 *
 * `America/New_York` rather than -4 or -5, so the two DST transitions a year are the runtime's problem
 * rather than this code's. On the spring-forward night 02:00-02:59 does not exist locally and on the
 * autumn one 01:00-01:59 happens twice; neither changes which **day** an instant falls on, which is all
 * this reads.
 */
final readonly class CallOrderDates
{
    /** What the recording provider publishes. Verified against the order it produced. */
    private const CALL_TIME_ZONE = 'UTC';

    /**
     * The store's own calendar, which is what Order58 groups orders by. Also what the order records
     * state about themselves: every sampled order carries `"timezone": "America/New_York"`.
     */
    public const BUSINESS_ZONE = 'America/New_York';

    /**
     * The distinct business days these call times belong to, oldest first.
     *
     * Sorted so a caller processes them predictably and a test can assert the whole list rather than a
     * set. Deduplicated because fifty calls on one evening are one day's orders and must cost one
     * request, not fifty.
     *
     * A timestamp that cannot be read is **dropped**, never replaced with today or with a page date:
     * syncing the wrong day is worse than syncing nothing, because it looks like it worked.
     *
     * @param list<string> $callTimes raw `callTime` values, exactly as the provider sent them
     *
     * @return list<string> `Y-m-d` in the business timezone
     */
    public static function fromCallTimes(array $callTimes): array
    {
        $dates = [];

        foreach ($callTimes as $callTime) {
            $date = self::businessDate($callTime);

            if ($date !== null) {
                $dates[$date] = true;
            }
        }

        /** @var list<string> $unique keys are the formatted dates, so the list is already strings */
        $unique = array_keys($dates);
        sort($unique);

        return $unique;
    }

    /**
     * One call's business day, or null when its timestamp cannot be read with certainty.
     *
     * The **whole** timestamp is parsed, not the leading date: taking the date off the front of a UTC
     * string and calling it a local day is precisely the error this class exists to prevent.
     */
    public static function businessDate(string $callTime): ?string
    {
        if ($callTime === '') {
            return null;
        }

        try {
            $utc = new DateTimeImmutable($callTime, new DateTimeZone(self::CALL_TIME_ZONE));
        } catch (\Exception) {
            // Unparseable, which the provider's undocumented format makes a real possibility. Dropped.
            return null;
        }

        return $utc->setTimezone(new DateTimeZone(self::BUSINESS_ZONE))->format('Y-m-d');
    }
}
