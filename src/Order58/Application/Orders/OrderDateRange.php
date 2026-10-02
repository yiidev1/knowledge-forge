<?php

declare(strict_types=1);

namespace App\Order58\Application\Orders;

use DateTimeImmutable;
use DateTimeZone;

use function preg_match;

/**
 * The window one sync may ask for: at most six inclusive calendar dates, end not before start.
 *
 * ## Counted in dates, never in seconds
 *
 * The span is measured with `DateTimeImmutable::diff()` in UTC, not by subtracting timestamps and
 * dividing by 86400. A day is not always 86400 seconds — the US daylight-saving transitions make one 23
 * hours and another 25 — so the arithmetic shortcut silently miscounts a range that straddles one, and
 * `America/New_York` is the timezone every order in the sample carries.
 *
 * Fixing both ends to UTC midnight makes the count a pure calendar question, which is what "six dates"
 * actually means.
 *
 * ## Inclusive, and a single date counts
 *
 * `2026-09-30 → 2026-10-05` is six dates and allowed; `→ 2026-10-06` is seven and refused.
 *
 * The two ends may be **equal**, which is how somebody syncs one day — most often today. An earlier
 * version required the end to be strictly later, which made the commonest request of all impossible to
 * express: there was no way to ask for a single date. Only an end *before* its start is refused now,
 * because that is a mistake rather than a request.
 */
final readonly class OrderDateRange
{
    public const MAX_DATES = 6;

    private function __construct(
        public string $from,
        public string $to,
    ) {}

    /**
     * @return array{0: self|null, 1: string|null} the range, or null and the reason it was refused
     */
    public static function create(string $from, string $to): array
    {
        $start = self::parse($from);
        if ($start === null) {
            return [null, 'Start date must be a real date in YYYY-MM-DD format.'];
        }

        $end = self::parse($to);
        if ($end === null) {
            return [null, 'End date must be a real date in YYYY-MM-DD format.'];
        }

        if ($end < $start) {
            return [null, 'End date cannot be earlier than start date.'];
        }

        // +1 because both ends count: 30 Sep to 5 Oct is a five-day difference and six dates, and the
        // same date at both ends is a difference of zero and one date.
        $dates = (int) $start->diff($end)->days + 1;

        if ($dates > self::MAX_DATES) {
            return [null, sprintf(
                'You can sync a maximum of %d calendar dates at a time, and this range covers %d.',
                self::MAX_DATES,
                $dates,
            )];
        }

        return [new self($start->format('Y-m-d'), $end->format('Y-m-d')), null];
    }

    public function label(): string
    {
        // A single day reads as the date, not as "X to X" — which is how the commonest request would
        // otherwise be described.
        return $this->from === $this->to ? $this->from : $this->from . ' to ' . $this->to;
    }

    /**
     * A real calendar date in exactly `YYYY-MM-DD`.
     *
     * The format is checked before parsing because `DateTimeImmutable` is generous — it accepts
     * `2026-9-1`, `30 Sep 2026` and a good deal more — and the provider's filter is not. The round-trip
     * comparison afterwards is what rejects `2026-02-31`, which parses happily into 3 March.
     */
    private static function parse(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
