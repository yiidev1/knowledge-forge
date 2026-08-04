<?php

declare(strict_types=1);

namespace App\Agent\Web\Chat;

use App\Agent\Application\CurrentAgent;
use App\Agent\Domain\AgentConversationRepositoryInterface;
use App\Chat\Domain\Conversation;
use App\Chat\Domain\Exception\ConversationNotFound;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Legacy agent conversation URL → slug chat when authorized; otherwise 404.
 */
final readonly class ShowAction
{
    public function __construct(
        private AgentStoreResolver $resolver,
        private AgentConversationRepositoryInterface $conversations,
        private CurrentAgent $currentAgent,
        private Redirect $redirect,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, #[RouteArgument] int $conversationId): ResponseInterface
    {
        $knowledgeBase = $this->resolver->resolve($slug);
        $conversation = $this->conversations->findForAgent(
            $conversationId,
            $knowledgeBase->id(),
            $this->currentAgent->get()->adminId,
        );

        if (!$conversation instanceof Conversation) {
            throw ConversationNotFound::inKnowledgeBase($conversationId, $knowledgeBase->id());
        }

        return $this->redirect->toRoute('agent.chat.index', ['slug' => $slug]);
    }
}
