<?php

declare(strict_types=1);

namespace App\Agent\Web\Chat;

use App\Agent\Application\CurrentAgent;
use App\Agent\Domain\AgentConversationRepositoryInterface;
use App\Document\Domain\DocumentRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * A store's chat home for an agent (GET /agent/stores/{slug}/chat): the agent's own conversations for this
 * store, plus a box to start a new one.
 */
final readonly class IndexAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AgentStoreResolver $resolver,
        private AgentConversationRepositoryInterface $conversations,
        private DocumentRepositoryInterface $documents,
        private CurrentAgent $currentAgent,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->resolver->resolve($slug);

        return $this->viewRenderer
            ->withLayout('@src/Agent/Web/Layout/layout.php')
            ->render(__DIR__ . '/template', [
                'knowledgeBase' => $knowledgeBase,
                'conversations' => $this->conversations->findForAgentInKnowledgeBase(
                    $knowledgeBase->id(),
                    $this->currentAgent->get()->adminId,
                ),
                'chatReady' => $this->documents->countReadyForKnowledgeBase($knowledgeBase->id()) > 0,
            ]);
    }
}
