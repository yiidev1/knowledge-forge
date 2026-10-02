<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Orders;

use App\Order58\Application\Orders\OrderDateRange;
use Codeception\Test\Unit;

/**
 * The six-date window. **No test here reaches a network or a database.**
 *
 * The rule is small and the ways to get it wrong are not: an off-by-one in "inclusive", a `<=` where the
 * contract says `<`, and — the one that only shows up twice a year — counting days by dividing seconds
 * by 86400 across a daylight-saving boundary.
 */
final class OrderDateRangeTest extends Unit
{
    /**
     * @dataProvider accepted
     */
    public function testAnAllowedRangeIsAccepted(string $from, string $to): void
    {
        [$range, $error] = OrderDateRange::create($from, $to);

        $this->assertNotNull($range, (string) $error);
        $this->assertSame($from, $range->from);
        $this->assertSame($to, $range->to);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function accepted(): array
    {
        return [
            // The client's own examples.
            'a single date' => ['2026-10-02', '2026-10-02'],
            'two dates' => ['2026-09-30', '2026-10-01'],
            'exactly six dates' => ['2026-09-30', '2026-10-05'],
            'three dates' => ['2026-10-01', '2026-10-03'],
            // Month, year and leap-day boundaries.
            'across a month end' => ['2026-01-30', '2026-02-02'],
            'across a year end' => ['2026-12-30', '2027-01-02'],
            'across a leap day' => ['2028-02-27', '2028-03-01'],
            // US daylight saving: one of these days is 23 hours long, the other 25. A seconds-based
            // count miscounts both, which is the whole reason this is calendar arithmetic.
            'across spring forward' => ['2027-03-12', '2027-03-16'],
            'across autumn back' => ['2027-11-05', '2027-11-09'],
        ];
    }

    /**
     * @dataProvider refused
     */
    public function testAnInvalidRangeIsRefusedWithAReason(string $from, string $to, string $expect): void
    {
        [$range, $error] = OrderDateRange::create($from, $to);

        $this->assertNull($range);
        $this->assertNotNull($error);
        $this->assertStringContainsString($expect, $error);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refused(): array
    {
        return [
            'seven dates' => ['2026-09-30', '2026-10-06', 'maximum of 6 calendar dates'],
            'far too many' => ['2026-01-01', '2026-12-31', 'maximum of 6 calendar dates'],
            // Only backwards is refused now. An equal pair is a one-day request, not a mistake.
            'inverted' => ['2026-10-02', '2026-10-01', 'cannot be earlier than start'],
            'inverted across a month' => ['2026-10-01', '2026-09-30', 'cannot be earlier than start'],
            'missing start' => ['', '2026-10-01', 'Start date'],
            'missing end' => ['2026-09-30', '', 'End date'],
            // DateTimeImmutable would happily accept all of these; the provider's filter would not.
            'unpadded' => ['2026-9-1', '2026-09-05', 'Start date'],
            'written out' => ['30 Sep 2026', '2026-10-01', 'Start date'],
            'us order' => ['09/30/2026', '2026-10-01', 'Start date'],
            'impossible day' => ['2026-02-31', '2026-03-02', 'Start date'],
            'not a leap year' => ['2027-02-29', '2027-03-02', 'Start date'],
            'with a time' => ['2026-09-30T00:00:00', '2026-10-01', 'Start date'],
            'junk' => ['yesterday', 'today', 'Start date'],
        ];
    }

    /** Both boundaries are pinned: one date at the bottom, six at the top. */
    public function testTheRangeIsOneToSixInclusiveDates(): void
    {
        $this->assertSame(6, OrderDateRange::MAX_DATES);

        $this->assertNotNull(OrderDateRange::create('2026-03-01', '2026-03-01')[0], 'one date');
        $this->assertNotNull(OrderDateRange::create('2026-03-01', '2026-03-06')[0], 'six dates');
        $this->assertNull(OrderDateRange::create('2026-03-01', '2026-03-07')[0], 'seven dates');
        $this->assertNull(OrderDateRange::create('2026-03-02', '2026-03-01')[0], 'backwards');
    }

    /**
     * **The fix.** Equal dates are a one-day request and must survive untouched.
     *
     * Nothing is widened to make a single day "work": the two dates go to the provider exactly as typed,
     * because a range quietly extended by a day would mirror orders nobody asked for.
     *
     * @dataProvider singleDays
     */
    public function testASingleDayIsAcceptedAndCarriedThroughUnchanged(string $date): void
    {
        [$range, $error] = OrderDateRange::create($date, $date);

        $this->assertNotNull($range, (string) $error);
        $this->assertSame($date, $range->from);
        $this->assertSame($date, $range->to);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function singleDays(): array
    {
        return [
            "the client's example" => ['2026-10-02'],
            'today' => [(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d')],
            'a month end' => ['2026-01-31'],
            'a year end' => ['2026-12-31'],
            'a leap day' => ['2028-02-29'],
        ];
    }

    /** A one-day range reads as the date, not as "X to X". */
    public function testASingleDayLabelsAsOneDate(): void
    {
        [$range] = OrderDateRange::create('2026-10-02', '2026-10-02');

        $this->assertSame('2026-10-02', $range->label());
    }

    public function testTheLabelReadsAsARange(): void
    {
        [$range] = OrderDateRange::create('2026-09-30', '2026-10-01');

        $this->assertSame('2026-09-30 to 2026-10-01', $range->label());
    }
}
