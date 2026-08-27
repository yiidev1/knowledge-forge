<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Index;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\WorkerHealthService;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\Shared\Application\Time\AppTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function ceil;
use function is_string;
use function max;
use function min;

/**
 * The global conversions list.
 *
 * Every authorized administrator's jobs, newest first — this is a shared demo, and the uploader is
 * shown as a column rather than used as a filter.
 */
final readonly class Action
{
    /**
     * Conversions per page.
     *
     * Fifteen rather than fifty because each row carries three transcript previews and wraps to several
     * lines, so a longer page is mostly scrolling. The pager is what makes the rest reachable.
     */
    private const LIMIT = 15;

    /** Characters of each transcript column shown in the table. */
    private const PREVIEW_LENGTH = 80;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private TranscriptionJobRepositoryInterface $jobs,
        private WorkerHealthService $workerHealth,
        private AudioToTextSettings $settings,
        private AppTimeZone $appTimeZone,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $total = $this->jobs->countAll();
        $pageCount = max(1, (int) ceil($total / self::LIMIT));

        $rawPage = $request->getQueryParams()['page'] ?? null;
        // Clamped rather than 404'd: a stale bookmark to page 9 after jobs were removed should show
        // the last page, not an error. Page 1 is also the answer for anything unparseable.
        $page = min(max(1, (int) (is_string($rawPage) ? $rawPage : '1')), $pageCount);

        $items = $this->jobs->recent(self::LIMIT, self::PREVIEW_LENGTH, ($page - 1) * self::LIMIT);

        $hasActive = false;
        foreach ($items as $item) {
            if ($item->status->isActive()) {
                $hasActive = true;

                break;
            }
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'items' => $items,
                'total' => $total,
                'limit' => self::LIMIT,
                'page' => $page,
                'pageCount' => $pageCount,
                // Drives the auto-refresh: the list reloads only while something on it can still change.
                'hasActive' => $hasActive,
                'pollSeconds' => $this->settings->transcription->pollSeconds(),
                'summary' => $this->jobs->summary(),
                'worker' => $this->workerHealth->status(),
                'retentionHours' => $this->settings->transcription->retentionHours(),
                'appTimeZone' => $this->appTimeZone,
            ])
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
