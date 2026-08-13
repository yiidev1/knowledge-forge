<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports;

use App\Reports\Domain\AgentUsageSort;
use App\Reports\Domain\AnswerStatusFilter;
use App\Reports\Domain\ChatTypeFilter;
use App\Reports\Domain\FeedbackFilter;
use App\Reports\Domain\RatingFilter;
use App\Reports\Domain\ScoreDisplay;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * The request-facing edges: nothing a visitor types may reach SQL or the page.
 *
 * Every filter is a closed set that degrades to "all", and the sort field is an allow-list whose accepted
 * values map to fixed expressions — so a crafted `?sort=` cannot become an ORDER BY fragment.
 */
final class ChatReportFiltersTest extends Unit
{
    public function testUnknownFilterValuesFallBackToAll(): void
    {
        foreach (["'; DROP TABLE messages; --", 'nonsense', '', '1'] as $junk) {
            assertSame(ChatTypeFilter::All, ChatTypeFilter::fromRequest($junk));
            assertSame(RatingFilter::All, RatingFilter::fromRequest($junk));
            assertSame(FeedbackFilter::All, FeedbackFilter::fromRequest($junk));
            assertSame(AnswerStatusFilter::All, AnswerStatusFilter::fromRequest($junk));
        }

        assertSame(ChatTypeFilter::All, ChatTypeFilter::fromRequest(null));
        assertSame(RatingFilter::Low, RatingFilter::fromRequest('low'));
        assertSame(AnswerStatusFilter::Unanswered, AnswerStatusFilter::fromRequest('unanswered'));
    }

    public function testRatingBucketBounds(): void
    {
        assertSame([1, 3], RatingFilter::Low->scoreRange());
        assertSame([4, 7], RatingFilter::Medium->scoreRange());
        assertSame([8, 10], RatingFilter::High->scoreRange());

        // These three are not numeric ranges — they are handled by their own predicates.
        assertNull(RatingFilter::All->scoreRange());
        assertNull(RatingFilter::Rated->scoreRange());
        assertNull(RatingFilter::Unrated->scoreRange());
    }

    public function testSortRejectsUnknownFieldsAndNeverEchoesThem(): void
    {
        $sort = AgentUsageSort::fromRequest('questions); DROP TABLE messages; --', 'desc');

        assertSame(AgentUsageSort::DEFAULT_FIELD, $sort->field);
        assertStringNotContainsString('DROP', $sort->orderBy());
        assertStringNotContainsString('DROP', $sort->field);
    }

    public function testSortDefaultsAreSensiblePerColumn(): void
    {
        // Metrics open "most first"; a name column opens A–Z.
        assertTrue(AgentUsageSort::fromRequest('questions', null)->descending);
        assertTrue(AgentUsageSort::fromRequest('low_ratings', null)->descending);
        assertFalse(AgentUsageSort::fromRequest('agent', null)->descending);

        // An explicit direction always wins.
        assertFalse(AgentUsageSort::fromRequest('questions', 'asc')->descending);
        assertTrue(AgentUsageSort::fromRequest('agent', 'desc')->descending);
    }

    public function testHeaderLinkFlipsOnlyTheActiveColumn(): void
    {
        $sort = new AgentUsageSort('questions', false);

        assertSame('desc', $sort->nextDirectionFor('questions'));
        assertSame('asc', $sort->nextDirectionFor('avg_rating'));
        assertSame('ascending', $sort->ariaFor('questions'));
        assertSame('none', $sort->ariaFor('avg_rating'));
        assertSame(' ▴', $sort->markerFor('questions'));
        assertSame('', $sort->markerFor('avg_rating'));
    }

    /** Agents with no rating must not sort as if they had scored zero. */
    public function testUnratedAgentsSortLastByRating(): void
    {
        assertStringNotContainsString('COALESCE', (new AgentUsageSort('avg_rating', true))->orderBy());
        assertSame(
            '[[avg_rating]] IS NULL, [[avg_rating]] DESC, [[agent_admin_id]] ASC',
            (new AgentUsageSort('avg_rating', true))->orderBy(),
        );
    }

    public function testScoreBandsMatchTheChatControlWording(): void
    {
        assertSame('Poor', ScoreDisplay::band(1));
        assertSame('Poor', ScoreDisplay::band(3));
        assertSame('Fair', ScoreDisplay::band(4));
        assertSame('Fair', ScoreDisplay::band(6));
        assertSame('Good', ScoreDisplay::band(7));
        assertSame('Good', ScoreDisplay::band(8));
        assertSame('Excellent', ScoreDisplay::band(9));
        assertSame('Excellent', ScoreDisplay::band(10));

        assertSame('8/10 · Good', ScoreDisplay::label(8));
        assertSame('poor', ScoreDisplay::bandSlug(2));
    }

    public function testDurationFormatting(): void
    {
        assertSame('0m', ScoreDisplay::duration(0));
        // A quick exchange really can be seconds; showing "0m" there would hide it.
        assertSame('45s', ScoreDisplay::duration(45));
        assertSame('12m', ScoreDisplay::duration(720));
        assertSame('4h 25m', ScoreDisplay::duration(15900));
        assertSame('—', ScoreDisplay::responseTime(null));
        assertSame('6.3s', ScoreDisplay::responseTime(6.3));
    }
}
