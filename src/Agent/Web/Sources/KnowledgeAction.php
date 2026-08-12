<?php

declare(strict_types=1);

namespace App\Agent\Web\Sources;

use App\Agent\Web\Chat\AgentStoreResolver;
use App\Chat\Web\Sources\SourceViews;
use App\Chat\Application\ChatKnowledgeSourcesService;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Agent: the knowledge an agent's store chat may use (GET /agent/stores/{slug}/chat/knowledge).
 *
 * Authorization is {@see AgentStoreResolver} — the SAME gate the agent chat itself uses, so a store an agent
 * may not chat with is a 404 here too and its existence is never revealed. The view is the admin's own
 * template: an agent sees the same read-only facts about their own store and no admin-only control.
 */
final readonly class KnowledgeAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AgentStoreResolver $resolver,
        private ChatKnowledgeSourcesService $sources,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->resolver->resolve($slug);
        $items = $this->sources->forKnowledgeBase($knowledgeBase);

        return $this->viewRenderer
            ->withLayout('@src/Agent/Web/Layout/layout.php')
            ->render(SourceViews::knowledge(), [
                'title' => $knowledgeBase->name() . ' — Knowledge',
                'contextName' => $knowledgeBase->name(),
                'items' => $items,
                'retrievableCount' => $this->sources->retrievableCount($items),
                'backUrl' => $this->urlGenerator->generate('agent.chat.index', ['slug' => $slug]),
                'backLabel' => 'Back to chat',
            ]);
    }
}
