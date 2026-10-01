<?php

declare(strict_types=1);

namespace App\Order58\Web\CallRecordings;

use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Domain\CallImportMode;
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Shared\Application\Time\AppTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_string;
use function max;
use function min;

/**
 * What has been downloaded, and when (GET /admin/order58/call-recordings/history).
 *
 * ## Only download-only activity
 *
 * Filtered on {@see CallImportMode::DownloadOnly}, so this shows the calls somebody asked to *listen*
 * to and nothing else. The imports that were asked for in order to be transcribed have their own
 * history on the calls page, and mixing the two would make both pages answer a question nobody asked:
 * the whole point of this feature is that those are two different intentions.
 *
 * It is the same table underneath — the mode column on the batch is the only thing separating them —
 * which is why this needed no storage of its own.
 *
 * ## It contacts nothing
 *
 * No provider call under any query string. Two local tables, read and rendered, so it works at full
 * speed from a machine outside the client's IP allowlist — which is most of them.
 */
final readonly class HistoryAction
{
    /**
     * One screen of calls. In line with the other admin tables, and small enough that a row carrying
     * three channel cells, two timestamps and an actions column still fits without horizontal scrolling.
     */
    private const PER_PAGE = 25;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private CallImportRepositoryInterface $imports,
        private AppTimeZone $appTimeZone,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $raw = $params['page'] ?? null;
        $page = is_string($raw) ? max(1, (int) $raw) : 1;

        $result = $this->imports->historyPage($page, self::PER_PAGE, CallImportMode::DownloadOnly);

        // Asked for page 900 of 4: show the last real page rather than an empty one. The repository has
        // already returned the total, so this costs no extra query.
        if ($page > $result->pageCount()) {
            $result = $this->imports->historyPage(
                $result->pageCount(),
                self::PER_PAGE,
                CallImportMode::DownloadOnly,
            );
        }

        return $this->viewRenderer
            // Statuses move underneath this table while recordings arrive, so a cached copy would show
            // finished work as still outstanding.
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/history.php', [
                'result' => $result,
                'page' => min($page, $result->pageCount()),
                'channels' => RecordingChannel::all(),
                'appTimeZone' => $this->appTimeZone,
                'pageUrl' => $this->urlGenerator->generate('order58.call-recordings.history'),
                'recordingsUrl' => $this->urlGenerator->generate('order58.call-recordings'),
                'retryUrl' => $this->urlGenerator->generate('order58.call-recordings.retry'),
            ]);
    }
}
