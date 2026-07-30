<?php

declare(strict_types=1);

namespace App\Ai\Web\Usage;

use App\Ai\Application\Usage\UsageCalculator;
use App\Ai\Application\Usage\UsageParams;
use App\Ai\Application\Usage\UsageSnapshotStoreInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * The OpenAI usage and vector-store dashboard (GET /admin/openai-usage).
 *
 * Admin-only by virtue of sitting in the protected route group, and deliberately absent from every menu
 * — it is an operator diagnostic reached by direct URL.
 *
 * This action performs NO OpenAI calls. It reads the cached snapshot and renders it, so opening the page
 * costs nothing and cannot be used to drive traffic at the provider. Refreshing is an explicit,
 * CSRF-protected POST; see {@see SyncAction}.
 */
final readonly class IndexAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private UsageSnapshotStoreInterface $snapshots,
        private UsageCalculator $calculator,
        private UsageParams $params,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $sort = UsageSort::fromQuery($query['sort'] ?? null, $query['dir'] ?? null);

        $snapshot = $this->snapshots->latest();

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'snapshot' => $snapshot,
                'stores' => $snapshot === null ? [] : $sort->apply($snapshot->stores),
                'sort' => $sort,
                'calculator' => $this->calculator,
                'adminApiConfigured' => $this->params->adminApiConfigured,
            ])
            // This page lists infrastructure identifiers and cost figures. Keeping it out of shared and
            // browser caches means a back-button press after sign-out cannot redisplay it.
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('Pragma', 'no-cache');
    }
}
