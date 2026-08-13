<?php

declare(strict_types=1);

namespace App\Reports\Web\Chat;

use App\Reports\Contract\ChatReportReaderInterface;
use App\Reports\Domain\ReportDatePreset;
use App\Reports\Domain\ReportDateRange;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Domain\Clock\ClockInterface;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

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
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private ChatReportReaderInterface $reader,
        private ChatReportRequest $request,
        private AppTimeZone $appTimeZone,
        private ClockInterface $clock,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $now = $this->clock->now();

        $query = $this->request->build($params, $now);
        $range = $query->range;

        // Each table clamps its own out-of-range page independently, so paging one never disturbs another.
        $agents = $this->reader->agentUsage($query);
        if ($query->agentPage->number > $agents->pageCount()) {
            $agents = $this->reader->agentUsage($query->withPageFor('agent', $agents->pageCount()));
        }

        $stores = $this->reader->storeUsage($query);
        if ($query->storePage->number > $stores->pageCount()) {
            $stores = $this->reader->storeUsage($query->withPageFor('store', $stores->pageCount()));
        }

        $result = $this->reader->list($query);
        if ($query->qaPage->number > $result->pageCount()) {
            $result = $this->reader->list($query->withPageFor('qa', $result->pageCount()));
        }

        // Where a "View" click lands without JavaScript: the same record the dialog would have shown,
        // rendered into the page. It goes through the same filtered reader, so a question outside the
        // current period or filters is simply not found rather than quietly escaping them.
        $questionId = isset($params['question']) && is_scalar($params['question']) ? (int) $params['question'] : 0;
        $detail = $questionId > 0 ? $this->reader->findDetail($questionId, $query) : null;

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'query' => $query,
                'range' => $range,
                // The summary always covers the whole filtered range — paging a table must never move it.
                'summary' => $this->reader->summary($query),
                'agents' => $agents,
                'stores' => $stores,
                'result' => $result,
                'detail' => $detail,
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
}
