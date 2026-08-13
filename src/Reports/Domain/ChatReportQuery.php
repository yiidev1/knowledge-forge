<?php

declare(strict_types=1);

namespace App\Reports\Domain;

use function max;

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
        public int $page = 1,
        public int $perPage = 25,
        public AgentUsageSort $agentSort = new AgentUsageSort(),
    ) {}

    public function offset(): int
    {
        return (max(1, $this->page) - 1) * max(1, $this->perPage);
    }

    /**
     * The same filters with paging moved to another page — used by the action's out-of-range clamp.
     */
    public function withPage(int $page): self
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
            $page,
            $this->perPage,
            $this->agentSort,
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
