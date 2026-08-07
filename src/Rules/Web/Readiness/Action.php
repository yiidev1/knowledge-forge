<?php

declare(strict_types=1);

namespace App\Rules\Web\Readiness;

use App\Order58\Application\Order58SyncFreshnessService;
use App\Order58\Domain\Order58SyncType;
use App\Rules\Contract\RuleReadinessReaderInterface;
use App\Shared\Application\Time\AppTimeZone;
use App\Rules\Domain\RuleReadinessFilter;
use App\Rules\Domain\RuleReadinessQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_string;
use function max;
use function trim;

/**
 * Operational readiness view for materialized Order58 rule documents (GET /admin/order58/rules/readiness).
 *
 * The everyday monitoring page: count cards + a table answering "how many rule documents are ready / pending /
 * failed / disabled, and which document is in which stage". Classification/duplicate/ambiguous/review filters
 * live only on the advanced /rules/list page. Read-only; renders from local state (no OpenAI call).
 */
final readonly class Action
{
    private const PER_PAGE = 25;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private RuleReadinessReaderInterface $reader,
        private Order58SyncFreshnessService $freshness,
        private AppTimeZone $appTimeZone,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $search = is_string($params['q'] ?? null) ? trim((string) $params['q']) : '';
        $filter = RuleReadinessFilter::fromRequest(is_string($params['filter'] ?? null) ? (string) $params['filter'] : null);
        $page = is_string($params['page'] ?? null) ? max(1, (int) $params['page']) : 1;

        $result = $this->reader->list(new RuleReadinessQuery($search, $filter, $page, self::PER_PAGE));
        if ($page > $result->pageCount()) {
            $result = $this->reader->list(new RuleReadinessQuery($search, $filter, $result->pageCount(), self::PER_PAGE));
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'summary' => $this->reader->summary($search),
                'result' => $result,
                'search' => $search,
                'filter' => $filter,
                'page' => $result->currentPage(),
                'freshness' => $this->freshness->all()[Order58SyncType::Rules->value],
                'appTimeZone' => $this->appTimeZone,
            ]);
    }
}
