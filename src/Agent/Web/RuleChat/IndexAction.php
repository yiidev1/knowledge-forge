<?php

declare(strict_types=1);

namespace App\Agent\Web\RuleChat;

use App\Agent\Application\CurrentAgent;
use App\Chat\Application\ChatParams;
use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Application\RuleChatKnowledgeBaseResolver;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ChatAnswerScoreRepositoryInterface;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Web\ChatThreadParams;
use App\Chat\Web\MessageEditView;
use App\Chat\Web\MessageScoreView;
use App\Shared\Infrastructure\Markdown\MarkdownRenderer;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Agent Rule Chat (GET /agent/rule-chat). Lookup only — never creates a conversation.
 */
final readonly class IndexAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private RuleChatKnowledgeBaseResolver $resolver,
        private FindOrCreateThreadService $threads,
        private MessageRepositoryInterface $messages,
        private ChatAnswerScoreRepositoryInterface $scores,
        private CurrentAgent $currentAgent,
        private MarkdownRenderer $markdown,
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
        $scoreView = MessageScoreView::none();

        if ($knowledgeBase !== null) {
            $participant = ChatParticipant::agent($this->currentAgent->get()->adminId);
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
                    $chatReady,
                );
                $scoreView = MessageScoreView::compute($this->scores, $messages, $participant);
            }
        }

        return $this->viewRenderer
            ->withLayout('@src/Agent/Web/Layout/layout.php')
            ->render(__DIR__ . '/template', [
                'knowledgeBase' => $knowledgeBase,
                'conversation' => $conversation,
                'messages' => $messages,
                'hasOlder' => $hasOlder,
                'chatReady' => $chatReady,
                'unavailableMessage' => $unavailableMessage,
                'markdown' => $this->markdown,
                'editView' => $editView,
                'scoreView' => $scoreView,
                'maxQuestionLength' => $this->params->maxQuestionLength,
            ]);
    }
}
