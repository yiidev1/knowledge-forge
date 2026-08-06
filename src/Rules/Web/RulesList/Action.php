<?php

declare(strict_types=1);

namespace App\Rules\Web\RulesList;

use App\Rules\Contract\RuleReportReaderInterface;
use App\Rules\Domain\RuleReportFilter;
use App\Rules\Domain\RuleReportQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_string;
use function max;
use function min;
use function trim;

/**
 * The detailed, filterable per-rule listing (GET /admin/order58/rules/list).
 *
 * One page: a title search, filter chips (store matched / confirmed common / ambiguous / unmatched / pending /
 * materialized / …), a table of canonical rules (scope, classification, matched store, duplicate group size,
 * searchable-document status), and pagination. Renders entirely from local state — no API call.
 */
final readonly class Action
{
    private const PER_PAGE = 30;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private RuleReportReaderInterface $reader,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $rawSearch = $params['q'] ?? null;
        $rawFilter = $params['filter'] ?? null;
        $rawPage = $params['page'] ?? null;

        $search = is_string($rawSearch) ? trim($rawSearch) : '';
        $filter = RuleReportFilter::fromRequest(is_string($rawFilter) ? $rawFilter : null);
        $page = is_string($rawPage) ? max(1, (int) $rawPage) : 1;

        $result = $this->reader->list(new RuleReportQuery($search, $filter, $page, self::PER_PAGE));
        if ($page > $result->pageCount()) {
            $result = $this->reader->list(new RuleReportQuery($search, $filter, $result->pageCount(), self::PER_PAGE));
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'result' => $result,
                'search' => $search,
                'filter' => $filter,
                'page' => min($page, $result->pageCount()),
            ]);
    }
}
