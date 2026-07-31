<?php

declare(strict_types=1);

namespace App\Agent\Web\Chat;

use App\Agent\Application\CurrentAgent;
use App\Agent\Domain\AgentConversationRepositoryInterface;
use App\Chat\Domain\Conversation;
use App\Chat\Domain\Exception\ConversationNotFound;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Shared\Infrastructure\Markdown\MarkdownRenderer;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * One agent conversation (GET /agent/stores/{slug}/chat/{conversationId}). The conversation is resolved
 * scoped to both the store and the agent, so another agent's — or another store's — id yields a 404.
 */
final readonly class ShowAction
{
    private const RECENT_MESSAGE_LIMIT = 10;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AgentStoreResolver $resolver,
        private AgentConversationRepositoryInterface $conversations,
        private MessageRepositoryInterface $messages,
        private DocumentRepositoryInterface $documents,
        private MarkdownRenderer $markdown,
        private CurrentAgent $currentAgent,
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

        $readyDocuments = $this->documents->countReadyForKnowledgeBase($knowledgeBase->id());

        return $this->viewRenderer
            ->withLayout('@src/Agent/Web/Layout/layout.php')
            ->render(__DIR__ . '/show', [
                'knowledgeBase' => $knowledgeBase,
                'conversation' => $conversation,
                'messages' => $this->messages->findRecentByConversation($conversation->id, self::RECENT_MESSAGE_LIMIT),
                'chatReady' => $knowledgeBase->isReadyForChat() && $readyDocuments > 0,
                'markdown' => $this->markdown,
            ]);
    }
}
