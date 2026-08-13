<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports;

use App\Reports\Domain\AgentUsageRow;
use App\Reports\Domain\AnswerStatusFilter;
use App\Reports\Domain\ChatTypeFilter;
use App\Reports\Domain\RatingFilter;
use App\Reports\Domain\ReportPage;
use App\Reports\Domain\StoreUsageSort;
use App\Reports\Domain\UsageResult;
use App\Reports\Web\Chat\ChatReportRequest;
use App\Shared\Application\Time\AppTimeZone;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * The three tables page independently, and each page number arrives from a query string a visitor controls.
 *
 * What is worth proving here is that one table's page never leaks into another's, that a hostile or absurd
 * page number cannot produce a negative offset or an unbounded LIMIT, and that the store sort is as closed an
 * allow-list as the agent sort already is.
 */
final class ReportPagingTest extends Unit
{
    private AppTimeZone $timeZone;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->timeZone = new AppTimeZone('America/New_York');
        $this->now = new DateTimeImmutable('2026-05-20 12:00:00', new DateTimeZone('UTC'));
    }

    // ---------------------------------------------------------------- ReportPage

    public function testOffsetIsDerivedFromThePageNumber(): void
    {
        assertSame(0, (new ReportPage(1, 20))->offset());
        assertSame(20, (new ReportPage(2, 20))->offset());
        assertSame(40, (new ReportPage(3, 20))->offset());
    }

    /** A negative or zero page must never produce a negative OFFSET. */
    public function testAbsurdPageNumbersClampToTheFirstPage(): void
    {
        assertSame(0, (new ReportPage(0, 20))->offset());
        assertSame(0, (new ReportPage(-5, 20))->offset());
        assertSame(0, (new ReportPage(1, 0))->offset());
    }

    public function testPageCountIsAtLeastOneEvenWithNoRecords(): void
    {
        $page = new ReportPage(1, 20);
        assertSame(1, $page->pageCount(0));
        assertSame(1, $page->pageCount(20));
        assertSame(2, $page->pageCount(21));
    }

    public function testCurrentPageIsClampedToWhatActuallyExists(): void
    {
        assertSame(2, (new ReportPage(99, 20))->clamped(21));
        assertSame(1, (new ReportPage(99, 20))->clamped(0));
        assertSame(1, (new ReportPage(-3, 20))->clamped(100));
    }

    // ---------------------------------------------------------------- UsageResult

    public function testUsageResultReportsTheFullTotalNotThePageSize(): void
    {
        $result = new UsageResult([$this->agentRow()], 47, 2, 20);

        assertCount(1, $result->items);
        assertSame(47, $result->total);
        assertSame(3, $result->pageCount());
        assertSame(2, $result->currentPage());
    }

    public function testUsageResultCurrentPageIsClampedToItsPageCount(): void
    {
        assertSame(3, (new UsageResult([], 47, 99, 20))->currentPage());
        assertSame(1, (new UsageResult([], 0, 5, 20))->currentPage());
    }

    // ---------------------------------------------------------------- independence

    public function testEachTableHasItsOwnPageParameter(): void
    {
        $query = (new ChatReportRequest($this->timeZone))->build(
            ['agent_page' => '2', 'store_page' => '3', 'qa_page' => '4'],
            $this->now,
        );

        assertSame(2, $query->agentPage->number);
        assertSame(3, $query->storePage->number);
        assertSame(4, $query->qaPage->number);
    }

    public function testMovingOneTableLeavesTheOthersWhereTheyWere(): void
    {
        $query = (new ChatReportRequest($this->timeZone))->build(
            ['agent_page' => '2', 'store_page' => '3', 'qa_page' => '4'],
            $this->now,
        );

        $moved = $query->withPageFor('qa', 9);

        assertSame(9, $moved->qaPage->number);
        assertSame(2, $moved->agentPage->number, 'the agent table must not move');
        assertSame(3, $moved->storePage->number, 'the store table must not move');
        assertSame(4, $query->qaPage->number, 'the original query is untouched');
    }

    public function testEveryFilterSurvivesAPageMove(): void
    {
        $params = [
            'from' => '2026-05-01',
            'to' => '2026-05-10',
            'type' => 'rule',
            'rating' => 'low',
            'status' => 'fallback',
            'agent' => '4242',
            'store' => '77',
            'q' => 'delivery',
            'sort' => 'avg_rating',
            'dir' => 'asc',
            'ssort' => 'agents',
            'sdir' => 'asc',
        ];

        $moved = (new ChatReportRequest($this->timeZone))->build($params, $this->now)->withPageFor('agent', 5);

        assertSame(ChatTypeFilter::Rule, $moved->chatType);
        assertSame(RatingFilter::Low, $moved->rating);
        assertSame(AnswerStatusFilter::Fallback, $moved->status);
        assertSame(4242, $moved->agentAdminId);
        assertSame(77, $moved->knowledgeBaseId);
        assertSame('delivery', $moved->search);
        assertSame('avg_rating', $moved->agentSort->field);
        assertFalse($moved->agentSort->descending);
        assertSame('agents', $moved->storeSort->field);
        assertSame('2026-05-01', $moved->range->from);
        assertSame('2026-05-10', $moved->range->to);
        assertSame(5, $moved->agentPage->number);
    }

    public function testJunkPageNumbersFallBackToTheFirstPage(): void
    {
        $request = new ChatReportRequest($this->timeZone);

        foreach (['0', '-4', 'abc', '', '1.5', ['nested']] as $value) {
            $query = $request->build(['qa_page' => $value], $this->now);
            assertSame(1, $query->qaPage->number, 'a page that is not a positive integer must fall back to 1');
        }

        // An injection attempt is reduced to the integer in front of it; no request text reaches the SQL.
        $injected = $request->build(['qa_page' => '2; DROP TABLE messages'], $this->now);
        assertSame(2, $injected->qaPage->number);
        assertSame(20, $injected->qaPage->offset());
    }

    public function testPerPageIsFixedByTheServerAndNotTakenFromTheQueryString(): void
    {
        $query = (new ChatReportRequest($this->timeZone))->build(['per_page' => '10000', 'limit' => '10000'], $this->now);

        assertSame(ChatReportRequest::AGENTS_PER_PAGE, $query->agentPage->perPage);
        assertSame(ChatReportRequest::STORES_PER_PAGE, $query->storePage->perPage);
        assertSame(ChatReportRequest::QA_PER_PAGE, $query->qaPage->perPage);
    }

    /** The dialog asks for a smaller page than the page does; nothing else about the query may change. */
    public function testDrilldownPageSizeOverridesOnlyTheDetailTable(): void
    {
        $query = (new ChatReportRequest($this->timeZone))->build(
            ['agent' => '99'],
            $this->now,
            ChatReportRequest::DRILLDOWN_PER_PAGE,
        );

        assertSame(ChatReportRequest::DRILLDOWN_PER_PAGE, $query->qaPage->perPage);
        assertSame(ChatReportRequest::AGENTS_PER_PAGE, $query->agentPage->perPage);
        assertSame(99, $query->agentAdminId);
    }

    // ---------------------------------------------------------------- store sort

    public function testStoreSortRejectsUnknownFieldsAndNeverEchoesThem(): void
    {
        $sort = StoreUsageSort::fromRequest('questions); DROP TABLE messages; --', 'desc');

        assertSame(StoreUsageSort::DEFAULT_FIELD, $sort->field);
        assertStringContainsString('[[questions]]', $sort->orderBy());
        assertSame('', $sort->markerFor('questions); DROP TABLE messages; --'));
    }

    public function testStoreSortIsIndependentOfTheAgentSort(): void
    {
        $query = (new ChatReportRequest($this->timeZone))->build(
            ['sort' => 'avg_rating', 'dir' => 'asc', 'ssort' => 'fallback', 'sdir' => 'desc'],
            $this->now,
        );

        assertSame('avg_rating', $query->agentSort->field);
        assertSame('fallback', $query->storeSort->field);
        assertNotSame($query->agentSort->field, $query->storeSort->field);
    }

    public function testStoresWithoutARatingSortLast(): void
    {
        assertStringContainsString('IS NULL', (new StoreUsageSort('avg_rating', true))->orderBy());
    }

    public function testStoreSortDirectionTogglesPerColumn(): void
    {
        $descending = new StoreUsageSort('questions', true);
        assertSame('asc', $descending->nextDirectionFor('questions'), 'clicking the active column reverses it');
        assertSame('descending', $descending->ariaFor('questions'));
        assertSame('none', $descending->ariaFor('store'));

        $ascending = new StoreUsageSort('questions', false);
        assertSame('desc', $ascending->nextDirectionFor('questions'), 'and reverses back');
        assertSame('ascending', $ascending->ariaFor('questions'));

        // Counts open descending and names open A-Z, the same defaults the agent table uses.
        assertTrue(StoreUsageSort::fromRequest('low_ratings', null)->descending);
        assertTrue(StoreUsageSort::fromRequest('fallback', null)->descending);
        assertFalse(StoreUsageSort::fromRequest('store', null)->descending);
    }

    // ---------------------------------------------------------------- answered status

    public function testAnsweredIsASelectableStatusWithItsOwnLabel(): void
    {
        assertSame(AnswerStatusFilter::Answered, AnswerStatusFilter::fromRequest('answered'));
        assertSame('Answered', AnswerStatusFilter::Answered->label());
        assertTrue(AnswerStatusFilter::Answered !== AnswerStatusFilter::All);
    }

    private function agentRow(): AgentUsageRow
    {
        return new AgentUsageRow(
            agentAdminId: 1,
            agentName: 'Agent',
            agentUsername: 'agent',
            questions: 1,
            storeQuestions: 1,
            ruleQuestions: 0,
            answers: 1,
            ratedAnswers: 0,
            averageRating: null,
            lowRatings: 0,
            comments: 0,
            sessions: 1,
            chatSeconds: 10,
            averageResponseSeconds: 5.0,
            lastActivityAt: null,
            lastLoginAt: null,
        );
    }
}
