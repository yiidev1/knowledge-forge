<?php

declare(strict_types=1);

namespace App\Tests\Unit\Practice;

use App\Practice\Web\Assign\FilterReturn;
use PHPUnit\Framework\TestCase;

/**
 * Which filter an assignment redirects back to.
 *
 * The value arrives in a POST body, so it is attacker-controlled: it reaches a `Location` header, and
 * the only reason that is safe is that nothing but the two shapes the listing emits is ever passed on.
 * These cases are what makes that a checked fact.
 */
final class FilterReturnTest extends TestCase
{
    public function testATopicIdIsCarriedBack(): void
    {
        self::assertSame(['topics[0]' => '42'], FilterReturn::arguments(['42']));
    }

    public function testUnassignedIsCarriedBack(): void
    {
        self::assertSame(['topics[0]' => 'none'], FilterReturn::arguments(['none']));
    }

    /** **The whole selection**, not the first of it: the listing filters by several topics at once. */
    public function testEveryChosenTopicIsCarriedBack(): void
    {
        self::assertSame(
            ['topics[0]' => '42', 'topics[1]' => '7', 'topics[2]' => 'none'],
            FilterReturn::arguments(['42', '7', 'none']),
        );
    }

    /** A single value arrives as a scalar when the form had one hidden field. */
    public function testAScalarIsCarriedBack(): void
    {
        self::assertSame(['topics[0]' => '42'], FilterReturn::arguments('42'));
    }

    /** The indices stay contiguous, so a dropped value does not leave a hole in the query string. */
    public function testOneBadValueAmongGoodOnesCostsOnlyItself(): void
    {
        self::assertSame(
            ['topics[0]' => '42', 'topics[1]' => 'none'],
            FilterReturn::arguments(['42', 'rubbish', 'none']),
        );
    }

    public function testTheSameChoiceTwiceIsOneChoice(): void
    {
        self::assertSame(['topics[0]' => '42'], FilterReturn::arguments(['42', '42', '42']));
    }

    /** A crafted body cannot produce an unbounded redirect URL. */
    public function testTheCarriedSelectionIsBounded(): void
    {
        $many = [];

        for ($i = 1; $i <= FilterReturn::MAX * 3; $i++) {
            $many[] = (string) $i;
        }

        self::assertCount(FilterReturn::MAX, FilterReturn::arguments($many));
    }

    public function testNothingPostedCarriesNothing(): void
    {
        self::assertSame([], FilterReturn::arguments([]));
        self::assertSame([], FilterReturn::arguments(null));
    }

    /**
     * Anything else is dropped, and dropping it means landing on the unfiltered list.
     *
     * @dataProvider rejected
     */
    public function testAnythingElseIsDropped(string $posted): void
    {
        self::assertSame([], FilterReturn::arguments([$posted]));
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'absent' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'a word' => ['all'];
        yield 'uppercase' => ['NONE'];
        yield 'padded' => [' none'];
        yield 'another parameter' => ['none&page=9'];
        yield 'a path' => ['../../admin'];
        yield 'a url' => ['https://example.com'];
        yield 'crlf' => ["none\r\nX-Evil: 1"];
        yield 'too long' => ['12345678901234567890'];
    }
}
