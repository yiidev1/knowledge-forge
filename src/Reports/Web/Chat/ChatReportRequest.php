<?php

declare(strict_types=1);

namespace App\Reports\Web\Chat;

use App\Reports\Domain\AgentUsageSort;
use App\Reports\Domain\AnswerStatusFilter;
use App\Reports\Domain\ChatReportQuery;
use App\Reports\Domain\ChatTypeFilter;
use App\Reports\Domain\FeedbackFilter;
use App\Reports\Domain\RatingFilter;
use App\Reports\Domain\ReportDateRange;
use App\Reports\Domain\ReportPage;
use App\Reports\Domain\StoreUsageSort;
use App\Shared\Application\Time\AppTimeZone;
use DateTimeImmutable;

use function is_string;
use function max;
use function trim;

/**
 * Turns raw query parameters into a validated {@see ChatReportQuery}.
 *
 * Shared by the report page and the drill-down endpoint on purpose: a drill-down link is just the report URL
 * with extra filters, so both must read those parameters through the same rules. If they parsed
 * independently, a metric's displayed count and the records behind it could disagree — which is exactly the
 * bug this class exists to make impossible.
 *
 * Nothing here trusts the request: dates parse strictly in the application timezone, every filter is a
 * closed enum that degrades to "all", sorts are allow-listed, and page numbers are clamped to >= 1.
 */
final readonly class ChatReportRequest
{
    /** Rows per table. Deliberately modest — three tables share one screen. */
    public const AGENTS_PER_PAGE = 20;
    public const STORES_PER_PAGE = 20;
    public const QA_PER_PAGE = 20;

    /** Rows inside a drill-down dialog, which is a reading surface rather than a working one. */
    public const DRILLDOWN_PER_PAGE = 15;

    public function __construct(
        private AppTimeZone $appTimeZone,
    ) {}

    /**
     * @param array<array-key, mixed> $params
     */
    public function build(array $params, DateTimeImmutable $now, ?int $qaPerPage = null): ChatReportQuery
    {
        $range = ReportDateRange::fromRequest(
            $this->string($params, 'from'),
            $this->string($params, 'to'),
            $this->appTimeZone,
            $now,
        );

        return new ChatReportQuery(
            range: $range,
            chatType: ChatTypeFilter::fromRequest($this->string($params, 'type')),
            rating: RatingFilter::fromRequest($this->string($params, 'rating')),
            feedback: FeedbackFilter::fromRequest($this->string($params, 'feedback')),
            status: AnswerStatusFilter::fromRequest($this->string($params, 'status')),
            agentAdminId: $this->positiveInt($params, 'agent'),
            knowledgeBaseId: $this->positiveInt($params, 'store'),
            search: trim($this->string($params, 'q') ?? ''),
            agentPage: new ReportPage($this->page($params, 'agent_page'), self::AGENTS_PER_PAGE),
            storePage: new ReportPage($this->page($params, 'store_page'), self::STORES_PER_PAGE),
            qaPage: new ReportPage($this->page($params, 'qa_page'), $qaPerPage ?? self::QA_PER_PAGE),
            agentSort: AgentUsageSort::fromRequest($params['sort'] ?? null, $params['dir'] ?? null),
            storeSort: StoreUsageSort::fromRequest($params['ssort'] ?? null, $params['sdir'] ?? null),
        );
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function string(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function positiveInt(array $params, string $key): ?int
    {
        $value = $this->string($params, $key);
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function page(array $params, string $key): int
    {
        $value = $this->string($params, $key);

        return $value === null ? 1 : max(1, (int) $value);
    }
}
