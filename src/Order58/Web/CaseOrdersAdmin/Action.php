<?php

declare(strict_types=1);

namespace App\Order58\Web\CaseOrdersAdmin;

use App\Order58\Application\CaseOrders\CaseOrderSyncCoordinator;
use App\Order58\Domain\CaseOrders\CaseOrderListQuery;
use App\Order58\Domain\CaseOrders\CaseOrderListReaderInterface;
use App\Order58\Domain\CaseOrders\CaseOrderSyncRunRepositoryInterface;
use App\Shared\Application\Time\AppTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function ceil;
use function max;
use function min;

/**
 * The admin Case Orders page: every stored order, and the synchronisation history behind them.
 *
 * ## Admin only, by placement
 *
 * The route is declared inside the `RequireAdminMiddleware` group, which is what "admin only" means in
 * this application — there is no per-action check anywhere, and adding one here would be the only one.
 * It is reachable from the Call Recordings page and is deliberately absent from every sidebar, so it
 * never appears in the Agent or Operation navigation.
 *
 * ## A GET that reads and nothing else
 *
 * No synchronisation is started here, under any parameter. Opening, filtering, sorting and paging are
 * reads — which is the rule the whole case order design turns on.
 */
final readonly class Action
{
    private const PER_PAGE = 25;

    /** History is short and read whole; a page of it beside the listing is enough. */
    private const RUNS_SHOWN = 20;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private CaseOrderListReaderInterface $orders,
        private CaseOrderSyncRunRepositoryInterface $runs,
        private CaseOrderSyncCoordinator $coordinator,
        private AppTimeZone $appTimeZone,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = CaseOrderListQuery::fromParams($request->getQueryParams());

        $total = $this->orders->count($query);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $query->page), $pages);

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'query' => $query,
                'items' => $this->orders->page($query, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
                'stores' => $this->orders->stores(),
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
                'perPage' => self::PER_PAGE,
                // History, newest first. Several runs may share a date — that is the point of the
                // run-keyed design, and the table shows them as separate rows.
                'runs' => $this->runs->page(self::RUNS_SHOWN),
                'runCount' => $this->runs->count(),
                'activeRun' => $this->coordinator->active(),
                'appTimeZone' => $this->appTimeZone,
                'pageUrl' => $this->urlGenerator->generate('order58.case-orders'),
                'recordingsUrl' => $this->urlGenerator->generate('order58.call-recordings'),
                'detailRoute' => 'order58.case-orders.detail',
            ]);
    }
}
