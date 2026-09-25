<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Integration\Order58Recording\CallSummary;
use App\Order58\Application\TodayCallFilter;
use App\Shared\Application\Time\AppTimeZone;
use Codeception\Test\Unit;
use DateTimeImmutable;

use function array_map;

/**
 * Which calls the page offers, and — more importantly — which it refuses to offer.
 *
 * The refusals are the interesting half. A call whose time cannot be read as a date has no `time=` to be
 * fetched with, and offering it would produce a confident request for a recording that is not there. The
 * provider's `callTime` format is undocumented, so "cannot be read" is a real state rather than a
 * defensive flourish.
 */
final class TodayCallFilterTest extends Unit
{
    private function filter(): TodayCallFilter
    {
        return new TodayCallFilter(new AppTimeZone('America/New_York'));
    }

    /**
     * @param list<array{string, string}> $calls [sessionId, callTime]
     *
     * @return list<CallSummary>
     */
    private function calls(array $calls): array
    {
        return array_map(
            static fn(array $call): CallSummary => new CallSummary($call[0], $call[1], '16547451'),
            $calls,
        );
    }

    /** Only the named day's calls, in the order the provider gave them. */
    public function testOnlyCallsOnTheNamedDateAreOffered(): void
    {
        $kept = $this->filter()->onDate($this->calls([
            ['22487129', '2026-09-24 06:09:16'],
            ['22487119', '2026-09-23 23:58:02'],
            ['22487109', '2026-09-24 01:12:26'],
            ['22487099', '2026-09-25 00:00:01'],
        ]), '2026-09-24');

        self::assertSame(
            ['22487129', '22487109'],
            array_map(static fn(CallSummary $c): string => $c->callSessionId, $kept),
        );
    }

    /**
     * A time this application cannot read as a date is not offered at all.
     *
     * @dataProvider unreadableTimes
     */
    public function testACallWhoseDateCannotBeReadIsNotOffered(string $callTime, string $why): void
    {
        self::assertSame(
            [],
            $this->filter()->onDate($this->calls([['22487129', $callTime]]), '2026-09-24'),
            $why,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public function unreadableTimes(): iterable
    {
        yield 'empty' => ['', 'Nothing to read.'];
        yield 'not a date' => ['yesterday afternoon', 'No format this application recognises.'];
        yield 'american order' => ['09/24/2026 06:09', 'Ambiguous, and not the format the fetch takes.'];
        yield 'impossible day' => ['2026-02-31 06:09:16', 'Shaped like a date, but no such day exists.'];
        yield 'epoch seconds' => ['1790000000', 'A number, not a date this can send.'];
    }

    /** A row with no session id, or one the recording request would refuse, is skipped. */
    public function testUnusableCallsAreSkipped(): void
    {
        $kept = $this->filter()->onDate([
            new CallSummary('', '2026-09-24 06:09:16', '16547451'),
            new CallSummary('22487129-caller', '2026-09-24 06:10:00', '16547452'),
            new CallSummary('22487139', '2026-09-24 06:11:00', '16547453'),
        ], '2026-09-24');

        self::assertCount(1, $kept, 'Only the one with a plain numeric session id survives.');
        self::assertSame('22487139', $kept[0]->callSessionId);
    }

    /**
     * "Today" is the configured business day, not the server's UTC date.
     *
     * The distinction is real: at 02:00 UTC on the 25th it is still the 24th in New York, and a call the
     * provider timestamps 2026-09-24 must still be listed.
     */
    public function testTodayIsTheBusinessDayRatherThanTheUtcDate(): void
    {
        $justAfterUtcMidnight = new DateTimeImmutable('2026-09-25 02:00:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-09-24', $this->filter()->businessDate($justAfterUtcMidnight));

        $kept = $this->filter()->today(
            $this->calls([['22487129', '2026-09-24 21:09:16'], ['22487139', '2026-09-25 09:00:00']]),
            $justAfterUtcMidnight,
        );

        self::assertCount(1, $kept);
        self::assertSame('22487129', $kept[0]->callSessionId);
    }

    /** An empty list stays empty rather than becoming an error. */
    public function testNoCallsIsNotAProblem(): void
    {
        self::assertSame([], $this->filter()->onDate([], '2026-09-24'));
    }
}
