<?php

declare(strict_types=1);

namespace App\Order58\Web\CallHistory;

use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Shared\Application\Time\AppTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_string;
use function max;
use function min;

/**
 * Import history across every store (GET /admin/order58/calls/history).
 *
 * ## Why this is its own page rather than a wider table on the calls page
 *
 * That page's history is deliberately store-specific and short: it answers "did the calls I just synced
 * work?", beside the form that queued them. This one answers a different question — "what has this
 * server been importing?" — which has no store to scope it to and grows without limit, so it needs
 * paging and a page of its own. Both read the same rows through the same repository; neither stores
 * anything.
 *
 * ## It contacts nothing
 *
 * Unlike the calls page, this one never reaches the recording provider under any query string. It reads
 * two local tables and renders, so it works at full speed from a machine outside the client's IP
 * allowlist — which is most of them.
 */
final readonly class Action
{
    /**
     * One screen of calls. In line with the 20–24 used by the other admin tables, and small enough that
     * a row carrying three channel cells plus a retry column still fits without horizontal scrolling.
     */
    private const PER_PAGE = 25;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private CallImportRepositoryInterface $imports,
        private AppTimeZone $appTimeZone,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $raw = $params['page'] ?? null;
        $page = is_string($raw) ? max(1, (int) $raw) : 1;

        $result = $this->imports->historyPage($page, self::PER_PAGE);

        // Asked for page 900 of 4: show the last real page rather than an empty one. The repository has
        // already returned the total, so this costs no extra query.
        if ($page > $result->pageCount()) {
            $result = $this->imports->historyPage($result->pageCount(), self::PER_PAGE);
        }

        return $this->viewRenderer
            // Statuses move underneath this table while recordings import, so a cached copy would show
            // finished work as still pending.
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'result' => $result,
                'page' => min($page, $result->pageCount()),
                'channels' => RecordingChannel::all(),
                'appTimeZone' => $this->appTimeZone,
            ]);
    }
}
