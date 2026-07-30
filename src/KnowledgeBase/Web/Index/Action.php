<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Web\Index;

use App\KnowledgeBase\Application\ListKnowledgeBasesService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Lists knowledge bases (GET /knowledge-bases).
 *
 * Archived bases are hidden by default and revealed with `?archived=1`, so the common view stays
 * focused while retired bases remain reachable.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private ListKnowledgeBasesService $listService,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $includeArchived = ($request->getQueryParams()['archived'] ?? null) === '1';

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'summaries' => $this->listService->list($includeArchived),
                'includeArchived' => $includeArchived,
            ]);
    }
}
