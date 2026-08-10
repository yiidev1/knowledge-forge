<?php

declare(strict_types=1);

namespace App\Chat\Web\RuleChat\Index;

use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\ChatParams;
use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Application\RuleChatKnowledgeBaseResolver;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Web\ChatThreadParams;
use App\Chat\Web\MessageEditView;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Markdown\MarkdownRenderer;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Admin Rule Chat (GET /admin/rule-chat). Lookup only — never creates a conversation.
 * History remains readable when Rule Chat is unavailable; the composer is disabled.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private RuleChatKnowledgeBaseResolver $resolver,
        private FindOrCreateThreadService $threads,
        private MessageRepositoryInterface $messages,
        private MarkdownRenderer $markdown,
        private CurrentAdmin $currentAdmin,
        private ClockInterface $clock,
        private ChatParams $params,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $knowledgeBase = $this->resolver->find();
        $chatReady = $this->resolver->isAvailable();
        $unavailableMessage = $chatReady ? null : $this->resolver->unavailableMessage();
        $conversation = null;
        $messages = [];
        $hasOlder = false;
        $editView = MessageEditView::none();

        if ($knowledgeBase !== null) {
            $participant = ChatParticipant::admin($this->currentAdmin->get()->id());
            $conversation = $this->threads->find($knowledgeBase->id(), $participant);

            if ($conversation !== null) {
                $messages = $this->messages->findRecentByConversation(
                    $conversation->id,
                    ChatThreadParams::RECENT_MESSAGE_LIMIT,
                );
                $hasOlder = $this->messages->countByConversation($conversation->id) > count($messages);
                $editView = MessageEditView::compute(
                    $this->messages,
                    $conversation->id,
                    $messages,
                    $chatReady,
                    $this->clock->now(),
                    $this->params->editWindowMinutes,
                );
            }
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'knowledgeBase' => $knowledgeBase,
                'conversation' => $conversation,
                'messages' => $messages,
                'hasOlder' => $hasOlder,
                'chatReady' => $chatReady,
                'unavailableMessage' => $unavailableMessage,
                'markdown' => $this->markdown,
                'editView' => $editView,
                'maxQuestionLength' => $this->params->maxQuestionLength,
            ]);
    }
}
