<?php

declare(strict_types=1);

namespace App\Rules\Web\GlobalBase;

use App\Rules\Contract\RuleReadinessReaderInterface;
use App\Rules\Domain\RuleReadinessFilter;
use App\Rules\Domain\RuleReadinessQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_string;
use function max;
use function trim;

/**
 * Hidden, URL-only diagnostic view of the hidden Global/Common Rules knowledge base
 * (GET /admin/order58/rules/global). It is deliberately NOT linked from the sidebar or any page — the hidden base
 * is excluded from every directory, so this is the one place to inspect what it contains.
 *
 * Read-only: the same readiness read model, scoped to the hidden base, plus the base's vector-store status.
 */
final readonly class Action
{
    private const PER_PAGE = 50;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private RuleReadinessReaderInterface $reader,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $search = is_string($params['q'] ?? null) ? trim((string) $params['q']) : '';
        $filter = RuleReadinessFilter::fromRequest(is_string($params['filter'] ?? null) ? (string) $params['filter'] : null);
        $page = is_string($params['page'] ?? null) ? max(1, (int) $params['page']) : 1;

        $result = $this->reader->list(new RuleReadinessQuery($search, $filter, $page, self::PER_PAGE, hiddenBaseOnly: true));
        if ($page > $result->pageCount()) {
            $result = $this->reader->list(new RuleReadinessQuery($search, $filter, $result->pageCount(), self::PER_PAGE, hiddenBaseOnly: true));
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'base' => $this->reader->hiddenBaseInfo(),
                'summary' => $this->reader->summary($search, hiddenBaseOnly: true),
                'result' => $result,
                'search' => $search,
                'filter' => $filter,
                'page' => $result->currentPage(),
            ]);
    }
}
