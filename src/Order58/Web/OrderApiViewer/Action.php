<?php

declare(strict_types=1);

namespace App\Order58\Web\OrderApiViewer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Read-only viewer for the case-order endpoint (GET /admin/order-api-viewer).
 *
 * ## What it is
 *
 * Six fields of each order the provider returns, and a Play button for each call recording. It reads
 * the API and renders it. It writes nothing: no database row, no file, no cache, no staged payload, and
 * no log of the response body.
 *
 * ## It spends from a rationed allowance, and that is deliberate
 *
 * The provider limits this endpoint — originally one request a day, since relaxed to several. **One
 * page load is one request; one Refresh is one more.** Nothing here polls or auto-refreshes, and
 * playing audio costs no further request because the recording address is derived on the server from
 * the payload already in hand. That is the floor for a live viewer, and the page says so on its face so
 * nobody treats Refresh as free.
 *
 * ## Isolated from the synchronisation that owns this endpoint
 *
 * `CaseOrderSyncService` imports these orders into the database under a lease and an active-run index.
 * None of that is touched: this reuses only {@see \App\Order58\Client\CaseOrders\CaseOrderClientInterface},
 * which is pure HTTP, so no run is claimed, nothing is staged and nothing is imported. The sync behaves
 * exactly as it did before this page existed.
 *
 * ## Every row is rendered; paging happens in the browser
 *
 * The provider returns the whole set in one response, so the page renders all of it and the client
 * script shows twenty-five rows at a time. Paging on the server would mean a page number in the URL,
 * and a page number in the URL means pressing "Next" calls the rationed endpoint again — which is
 * exactly the cost this page exists to keep to one per view.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private OrderFeed $feed,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // The API is called on every view of this page, which is what "read it live" means. There is no
        // cache to go stale and no background job to disagree with.
        $result = $this->feed->load();

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'result' => $result,
                'rows' => $result->rows,
                'perPage' => 25,
            ]);
    }
}
