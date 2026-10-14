<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\OrderGroupFilter;
use PHPUnit\Framework\TestCase;

/**
 * What a `?topics[]=` selection means, decided in exactly one place.
 *
 * The picker's ticked boxes, the pager's links, the hidden fields that survive an assignment and the
 * SQL that narrows the rows all read this. A disagreement between them shows as a page whose control
 * says one thing and whose rows say another. The parsing rule is also the only thing standing between
 * a hand-edited query string and the query, which is why "anything unrecognised filters nothing" is
 * pinned here rather than assumed.
 */
final class OrderGroupFilterTest extends TestCase
{
    public function testAMissingParameterIsNoFilter(): void
    {
        self::assertNull(OrderGroupFilter::fromQuery(null));
        self::assertNull(OrderGroupFilter::fromQuery([]));
        self::assertNull(OrderGroupFilter::fromQuery(''));
    }

    public function testOneTopicIsASelectionOfOne(): void
    {
        $filter = OrderGroupFilter::fromQuery(['42']);

        self::assertNotNull($filter);
        self::assertSame([42], $filter->topicIds);
        self::assertFalse($filter->includeUnassigned);
        self::assertTrue($filter->narrowsTopics());
        self::assertSame(['42'], $filter->queryValues());
    }

    /** A single value arrives as a scalar when somebody types `?topics=42`; the intent is the same. */
    public function testAScalarIsReadAsASelectionOfOne(): void
    {
        $filter = OrderGroupFilter::fromQuery('42');

        self::assertNotNull($filter);
        self::assertSame([42], $filter->topicIds);
    }

    public function testSeveralTopicsAreOneSelection(): void
    {
        $filter = OrderGroupFilter::fromQuery(['42', '7', '19']);

        self::assertNotNull($filter);
        self::assertSame([7, 19, 42], $filter->topicIds, 'sorted, so one selection has one shape');
        self::assertSame(3, $filter->chosenCount());
    }

    /** The same choice twice is one choice. */
    public function testDuplicatesCollapse(): void
    {
        $filter = OrderGroupFilter::fromQuery(['42', '42', '42']);

        self::assertNotNull($filter);
        self::assertSame([42], $filter->topicIds);
    }

    public function testNoneMeansUnassigned(): void
    {
        $filter = OrderGroupFilter::fromQuery(['none']);

        self::assertNotNull($filter);
        self::assertSame([], $filter->topicIds);
        self::assertTrue($filter->includeUnassigned);
        self::assertSame(['none'], $filter->queryValues());
    }

    /** **The combination the whole thing exists for**: a topic, or nothing yet. */
    public function testATopicAndUnassignedTogether(): void
    {
        $filter = OrderGroupFilter::fromQuery(['42', 'none']);

        self::assertNotNull($filter);
        self::assertSame([42], $filter->topicIds);
        self::assertTrue($filter->includeUnassigned);
        self::assertSame(['42', 'none'], $filter->queryValues());
        self::assertTrue($filter->hasValue('42'));
        self::assertTrue($filter->hasValue('none'));
        self::assertFalse($filter->hasValue('7'));
    }

    /**
     * Unrecognised values are dropped, and a selection made only of them filters nothing.
     *
     * @dataProvider unrecognised
     */
    public function testUnrecognisedValuesAreDropped(mixed $value): void
    {
        self::assertNull(OrderGroupFilter::fromQuery([$value]));
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
        yield 'sql-ish' => ['1 OR 1=1'];
        yield 'too long' => ['12345678901234567890'];
        yield 'a nested array' => [['42']];
        yield 'an integer, not a string' => [42];
        yield 'uppercase none' => ['NONE'];
    }

    /** One bad value among good ones costs only itself. */
    public function testOneBadValueDoesNotDiscardTheRest(): void
    {
        $filter = OrderGroupFilter::fromQuery(['42', 'nonsense', 'none']);

        self::assertNotNull($filter);
        self::assertSame([42], $filter->topicIds);
        self::assertTrue($filter->includeUnassigned);
    }

    /** A crafted query string cannot ask for an unbounded `IN` list. */
    public function testTheSelectionIsBounded(): void
    {
        $many = [];

        for ($i = 1; $i <= OrderGroupFilter::MAX_TOPICS * 3; $i++) {
            $many[] = (string) $i;
        }

        $filter = OrderGroupFilter::fromQuery($many);

        self::assertNotNull($filter);
        self::assertCount(OrderGroupFilter::MAX_TOPICS, $filter->topicIds);
    }

    /** The round trip the pager and the assignment redirect depend on. */
    public function testQueryValuesParseBackToTheSameSelection(): void
    {
        $filter = OrderGroupFilter::fromQuery(['42', '7', 'none']);

        self::assertNotNull($filter);

        $again = OrderGroupFilter::fromQuery($filter->queryValues());

        self::assertNotNull($again);
        self::assertSame($filter->topicIds, $again->topicIds);
        self::assertSame($filter->includeUnassigned, $again->includeUnassigned);
    }

    // ------------------------------------------------------------------ the second dimension

    public function testLinkedOrdersOnlyNarrowsNoTopics(): void
    {
        $filter = OrderGroupFilter::linkedOrdersOnly();

        self::assertTrue($filter->requireLinkedOrder);
        self::assertFalse($filter->narrowsTopics());
        self::assertSame([], $filter->queryValues());
    }

    public function testTheTwoDimensionsCompose(): void
    {
        $filter = OrderGroupFilter::fromQuery(['42', 'none'])?->withLinkedOrdersOnly();

        self::assertNotNull($filter);
        self::assertTrue($filter->requireLinkedOrder);
        self::assertSame([42], $filter->topicIds);
        self::assertTrue($filter->includeUnassigned);
    }

    /** Composing returns a new filter; the one already handed to a query is unchanged. */
    public function testComposingDoesNotMutateTheOriginal(): void
    {
        $original = OrderGroupFilter::fromQuery(['42']);

        self::assertNotNull($original);

        $narrowed = $original->withLinkedOrdersOnly();

        self::assertFalse($original->requireLinkedOrder);
        self::assertTrue($narrowed->requireLinkedOrder);
    }

    public function testTheConstantsAreWhatTheMarkupPrints(): void
    {
        self::assertSame('none', OrderGroupFilter::UNASSIGNED);
        self::assertSame('topics', OrderGroupFilter::PARAM);
    }
}
