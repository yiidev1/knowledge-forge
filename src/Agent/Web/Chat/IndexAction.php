<?php

declare(strict_types=1);

namespace App\Agent\Web\Chat;

use App\Agent\Application\CurrentAgent;
use App\Chat\Application\ChatAvailabilityPolicy;
use App\Chat\Application\ChatParams;
use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Web\ChatThreadParams;
use App\Chat\Web\MessageEditView;
use App\Shared\Infrastructure\Markdown\MarkdownRenderer;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Persistent agent chat for one store (GET /agent/stores/{slug}/chat).
 * Lookup only — never creates a conversation row.
 */
final readonly class IndexAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AgentStoreResolver $resolver,
        private FindOrCreateThreadService $threads,
        private MessageRepositoryInterface $messages,
        private ChatAvailabilityPolicy $availability,
        private CurrentAgent $currentAgent,
        private MarkdownRenderer $markdown,
        private ChatParams $params,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->resolver->resolve($slug);
        $reason = $this->availability->getUnavailableReason($knowledgeBase);
        $chatReady = $reason->isAvailable();
        $participant = ChatParticipant::agent($this->currentAgent->get()->adminId);
        $conversation = $this->threads->find($knowledgeBase->id(), $participant);
        $messages = [];
        $hasOlder = false;
        $editView = MessageEditView::none();

        if ($conversation !== null) {
            $messages = $this->messages->findRecentByConversation(
                $conversation->id,
                ChatThreadParams::RECENT_MESSAGE_LIMIT,
            );
            $hasOlder = $this->messages->countByConversation($conversation->id) > count($messages);
            $editView = MessageEditView::compute(
                $this->messages,
                $conversation->id,
                $chatReady,
            );
        }

        return $this->viewRenderer
            ->withLayout('@src/Agent/Web/Layout/layout.php')
            ->render(__DIR__ . '/template', [
                'knowledgeBase' => $knowledgeBase,
                'conversation' => $conversation,
                'messages' => $messages,
                'hasOlder' => $hasOlder,
                'chatReady' => $chatReady,
                'unavailableMessage' => $reason->message(),
                'markdown' => $this->markdown,
                'editView' => $editView,
                'maxQuestionLength' => $this->params->maxQuestionLength,
            ]);
    }
}
