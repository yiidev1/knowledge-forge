<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Web\Edit;

use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Renders the edit form for a knowledge base (GET /knowledge-bases/{slug}/edit), pre-filled with its
 * current details.
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
            ->render(__DIR__ . '/template', [
                'knowledgeBase' => $knowledgeBase,
                'values' => [
                    'name' => $knowledgeBase->name(),
                    'description' => (string) $knowledgeBase->description(),
                    'system_instructions' => (string) $knowledgeBase->systemInstructions(),
                ],
                'errors' => [],
            ]);
    }
}
