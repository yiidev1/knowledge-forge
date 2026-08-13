<?php

declare(strict_types=1);

namespace App\Reports\Domain;

/**
 * Everything the report reader needs: the resolved UTC window plus every validated filter.
 *
 * The date range scopes on the **question's** timestamp only. Once a question falls inside the window its
 * current answer is joined whatever its own timestamp is — otherwise an answer produced seconds after
 * midnight would detach from the question it answers, and the boundary rows would silently lose their
 * answers, ratings and grounding status.
 *
 * `$search` is deliberately narrower than the rest: it applies to the Questions & Answers table only, never
 * to the summary cards or the usage tables. Letting free text reshape the aggregates would mean every
 * headline number silently meant something different, and it would push a `LIKE '%…%'` into queries whose
 * whole job is to stay cheap.
 */
final readonly class ChatReportQuery
{
    /**
     * Inactivity after which chat activity is treated as a new session. Named here so the sessionisation
     * rule has exactly one home rather than being a bare `30` buried in SQL.
     */
    public const SESSION_GAP_MINUTES = 30;

    public function __construct(
        public ReportDateRange $range,
        public ChatTypeFilter $chatType = ChatTypeFilter::All,
        public RatingFilter $rating = RatingFilter::All,
        public FeedbackFilter $feedback = FeedbackFilter::All,
        public AnswerStatusFilter $status = AnswerStatusFilter::All,
        /** Order58 `admin_id`, or null for every agent. */
        public ?int $agentAdminId = null,
        /** `knowledge_bases.id`, or null for every store. */
        public ?int $knowledgeBaseId = null,
        /** Detail-table-only free text over question and answer content. */
        public string $search = '',
        /** Each table pages independently, so one pager can never move another table. */
        public ReportPage $agentPage = new ReportPage(),
        public ReportPage $storePage = new ReportPage(),
        public ReportPage $qaPage = new ReportPage(),
        public AgentUsageSort $agentSort = new AgentUsageSort(),
        public StoreUsageSort $storeSort = new StoreUsageSort(),
    ) {}

    /** @param 'agent'|'store'|'qa' $table */
    public function withPageFor(string $table, int $number): self
    {
        return new self(
            $this->range,
            $this->chatType,
            $this->rating,
            $this->feedback,
            $this->status,
            $this->agentAdminId,
            $this->knowledgeBaseId,
            $this->search,
            $table === 'agent' ? $this->agentPage->withNumber($number) : $this->agentPage,
            $table === 'store' ? $this->storePage->withNumber($number) : $this->storePage,
            $table === 'qa' ? $this->qaPage->withNumber($number) : $this->qaPage,
            $this->agentSort,
            $this->storeSort,
        );
    }

    /**
     * True when a filter other than the date range is active — lets the page word its empty state usefully.
     */
    public function hasActiveFilters(): bool
    {
        return $this->chatType !== ChatTypeFilter::All
            || $this->rating !== RatingFilter::All
            || $this->feedback !== FeedbackFilter::All
            || $this->status !== AnswerStatusFilter::All
            || $this->agentAdminId !== null
            || $this->knowledgeBaseId !== null
            || $this->search !== '';
    }
}
