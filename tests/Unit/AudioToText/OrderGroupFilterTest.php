<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\OrderGroupFilter;
use PHPUnit\Framework\TestCase;

/**
 * What a `?topic=` value means, decided in exactly one place.
 *
 * The store page's filter control, its pager links and the SQL that narrows its rows all read this, so
 * a disagreement between them would show as a page whose control says one thing and whose rows say
 * another. The parsing rule is also the only thing standing between a hand-edited query string and the
 * query, which is why "anything unrecognised filters nothing" is pinned here rather than assumed.
 */
final class OrderGroupFilterTest extends TestCase
{
    public function testAMissingParameterIsNoFilter(): void
    {
        self::assertNull(OrderGroupFilter::fromQuery(null));
    }

    public function testAnEmptyValueIsNoFilter(): void
    {
        self::assertNull(OrderGroupFilter::fromQuery(''));
    }

    public function testDigitsNameATopic(): void
    {
        $filter = OrderGroupFilter::fromQuery('42');

        self::assertNotNull($filter);
        self::assertSame(42, $filter->topicId);
        self::assertFalse($filter->onlyUnassigned);
        self::assertSame('42', $filter->queryValue());
    }

    public function testNoneMeansUnassigned(): void
    {
        $filter = OrderGroupFilter::fromQuery('none');

        self::assertNotNull($filter);
        self::assertTrue($filter->onlyUnassigned);
        self::assertNull($filter->topicId);
        self::assertSame('none', $filter->queryValue());
    }

    /**
     * Everything else filters nothing.
     *
     * A word, a negative, a float, a leading zero, whitespace, an array arriving as `?topic[]=` — each
     * one has to leave the reader on the unfiltered page rather than throw or narrow by accident.
     *
     * @dataProvider unrecognised
     */
    public function testAnythingElseIsNoFilter(mixed $value): void
    {
        self::assertNull(OrderGroupFilter::fromQuery($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function unrecognised(): iterable
    {
        yield 'a word' => ['everything'];
        yield 'negative' => ['-5'];
        yield 'zero' => ['0'];
        yield 'leading zero' => ['07'];
        yield 'a float' => ['4.2'];
        yield 'padded' => [' 42 '];
        yield 'sql-ish' => ["1 OR 1=1"];
        yield 'too long' => ['12345678901234567890'];
        yield 'an array' => [['42']];
        yield 'an integer, not a string' => [42];
        yield 'uppercase none' => ['NONE'];
    }

    /** The round trip the pager depends on: what it prints must parse back to the same filter. */
    public function testQueryValueParsesBackToTheSameFilter(): void
    {
        foreach ([OrderGroupFilter::forTopic(7), OrderGroupFilter::unassigned()] as $filter) {
            $again = OrderGroupFilter::fromQuery($filter->queryValue());

            self::assertNotNull($again);
            self::assertSame($filter->topicId, $again->topicId);
            self::assertSame($filter->onlyUnassigned, $again->onlyUnassigned);
        }
    }

    public function testTheConstantsAreWhatTheMarkupPrints(): void
    {
        self::assertSame('', OrderGroupFilter::ALL);
        self::assertSame('none', OrderGroupFilter::UNASSIGNED);
    }
}
