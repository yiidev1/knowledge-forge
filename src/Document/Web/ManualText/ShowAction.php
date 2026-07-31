<?php

declare(strict_types=1);

namespace App\Document\Web\ManualText;

use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * The "Add manual text" form (GET /knowledge-bases/{slug}/manual-text).
 */
final readonly class ShowAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private KnowledgeBaseFinder $finder,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/form', [
                'knowledgeBase' => $knowledgeBase,
                'mode' => 'create',
                'documentId' => null,
                'title' => '',
                'content' => '',
            ]);
    }
}
