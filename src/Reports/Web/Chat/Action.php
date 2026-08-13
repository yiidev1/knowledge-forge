<?php

declare(strict_types=1);

namespace App\Reports\Web\Chat;

use App\Reports\Contract\ChatReportReaderInterface;
use App\Reports\Domain\AgentUsageSort;
use App\Reports\Domain\AnswerStatusFilter;
use App\Reports\Domain\ChatReportQuery;
use App\Reports\Domain\ChatTypeFilter;
use App\Reports\Domain\ReportDatePreset;
use App\Reports\Domain\FeedbackFilter;
use App\Reports\Domain\RatingFilter;
use App\Reports\Domain\ReportDateRange;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Domain\Clock\ClockInterface;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_string;
use function max;
use function trim;

/**
 * Admin chat report (GET /admin/reports/chat).
 *
 * Read-only: it renders aggregates over chat that already happened and writes nothing, calls no provider,
 * and offers no action. Being inside the admin route group is what authorises it — this is deliberately a
 * separate read path from the participant-owned chat services, which exist to stop one participant reading
 * another's thread and must not be weakened to allow cross-agent reporting.
 *
 * Every query parameter is validated before it reaches the reader: dates are parsed strictly in the
 * application timezone, each filter is a closed enum that falls back to "all", and the sort field is an
 * allow-list. Nothing from the query string is echoed into the page.
 */
final readonly class Action
{
    private const PER_PAGE = 25;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private ChatReportReaderInterface $reader,
        private AppTimeZone $appTimeZone,
        private ClockInterface $clock,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $now = $this->clock->now();

        $range = ReportDateRange::fromRequest(
            $this->string($params, 'from'),
            $this->string($params, 'to'),
            $this->appTimeZone,
            $now,
        );

        $query = new ChatReportQuery(
            range: $range,
            chatType: ChatTypeFilter::fromRequest($this->string($params, 'type')),
            rating: RatingFilter::fromRequest($this->string($params, 'rating')),
            feedback: FeedbackFilter::fromRequest($this->string($params, 'feedback')),
            status: AnswerStatusFilter::fromRequest($this->string($params, 'status')),
            agentAdminId: $this->positiveInt($params, 'agent'),
            knowledgeBaseId: $this->positiveInt($params, 'store'),
            search: trim($this->string($params, 'q') ?? ''),
            page: $this->page($params),
            perPage: self::PER_PAGE,
            agentSort: AgentUsageSort::fromRequest($params['sort'] ?? null, $params['dir'] ?? null),
        );

        $result = $this->reader->list($query);
        // A filter change can leave the page number past the end; land on the last page instead of empty.
        if ($query->page > $result->pageCount()) {
            $result = $this->reader->list($query->withPage($result->pageCount()));
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'query' => $query,
                'range' => $range,
                'summary' => $this->reader->summary($query),
                'agents' => $this->reader->agentUsage($query),
                'stores' => $this->reader->storeUsage($query),
                'result' => $result,
                'page' => $result->currentPage(),
                'agentOptions' => $this->reader->agentOptions(),
                'storeOptions' => $this->reader->storeOptions(),
                'presets' => $this->presets($range, $now),
                'appTimeZone' => $this->appTimeZone,
            ]);
    }

    /**
     * Resolves every preset to concrete local dates here, so the template renders links and does no calendar
     * arithmetic of its own.
     *
     * @return list<array{label: string, from: string, to: string, active: bool}>
     */
    private function presets(ReportDateRange $range, DateTimeImmutable $now): array
    {
        $presets = [];
        foreach (ReportDatePreset::cases() as $preset) {
            [$from, $to] = $preset->range($this->appTimeZone, $now);
            $presets[] = [
                'label' => $preset->label(),
                'from' => $from,
                'to' => $to,
                'active' => $preset->matches($range, $this->appTimeZone, $now),
            ];
        }

        return $presets;
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
    private function page(array $params): int
    {
        $value = $this->string($params, 'page');

        return $value === null ? 1 : max(1, (int) $value);
    }
}
