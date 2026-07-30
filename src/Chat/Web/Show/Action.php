<?php

declare(strict_types=1);

namespace App\Chat\Web\Show;

use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Web\ConversationFinder;
use App\Document\Domain\DocumentRepositoryInterface;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Infrastructure\Markdown\MarkdownRenderer;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * A single conversation (GET /knowledge-bases/{slug}/chat/{conversationId}): the message thread with
 * citations and a box to ask a follow-up. The Markdown renderer is passed to the view so assistant
 * answers — untrusted model output — are rendered with HTML escaped.
 */
final readonly class Action
{
    /** Newest messages loaded on the initial chat page (older ones stay in the database). */
    private const RECENT_MESSAGE_LIMIT = 10;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private KnowledgeBaseFinder $finder,
        private ConversationFinder $conversations,
        private MessageRepositoryInterface $messages,
        private DocumentRepositoryInterface $documents,
        private MarkdownRenderer $markdown,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, #[RouteArgument] int $conversationId): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $conversation = $this->conversations->forKnowledgeBase($conversationId, $knowledgeBase->id());
        $readyDocuments = $this->documents->countReadyForKnowledgeBase($knowledgeBase->id());

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'knowledgeBase' => $knowledgeBase,
                'conversation' => $conversation,
                'messages' => $this->messages->findRecentByConversation($conversation->id, self::RECENT_MESSAGE_LIMIT),
                'chatReady' => $knowledgeBase->isReadyForChat() && $readyDocuments > 0,
                'markdown' => $this->markdown,
            ]);
    }
}
