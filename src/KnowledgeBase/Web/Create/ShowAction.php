<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Web\Create;

use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Renders the empty "new knowledge base" form (GET /knowledge-bases/create).
 */
final readonly class ShowAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
    ) {}

    public function __invoke(): ResponseInterface
    {
        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', ['values' => [], 'errors' => []]);
    }
}
