<?php

declare(strict_types=1);

namespace App\Chat\Web\Sources;

use App\Chat\Application\ChatKnowledgeSourcesService;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Admin: the knowledge a store chat may use (GET /knowledge-bases/{slug}/chat/knowledge).
 *
 * Authorization is inherited, not re-invented: the route sits inside the admin group, and
 * {@see KnowledgeBaseFinder} is the same lookup the chat page itself uses, so this page can only ever describe
 * a base the admin could already open. Read-only — it renders no mutating control.
 */
final readonly class KnowledgeAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private KnowledgeBaseFinder $finder,
        private ChatKnowledgeSourcesService $sources,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $items = $this->sources->forKnowledgeBase($knowledgeBase);

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(SourceViews::knowledge(), [
                'title' => $knowledgeBase->name() . ' — Knowledge',
                'contextName' => $knowledgeBase->name(),
                'items' => $items,
                'retrievableCount' => $this->sources->retrievableCount($items),
                'backUrl' => $this->urlGenerator->generate('chat.index', ['slug' => $slug]),
                'backLabel' => 'Back to chat',
            ]);
    }
}
