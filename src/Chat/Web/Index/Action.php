<?php

declare(strict_types=1);

namespace App\Chat\Web\Index;

use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\ChatAvailabilityPolicy;
use App\Chat\Application\ChatParams;
use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Web\ChatThreadParams;
use App\Chat\Web\MessageEditView;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Markdown\MarkdownRenderer;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Persistent admin chat for a knowledge base (GET /knowledge-bases/{slug}/chat).
 * Looks up this admin's thread; does not create a row. Missing thread → empty state.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private KnowledgeBaseFinder $finder,
        private FindOrCreateThreadService $threads,
        private MessageRepositoryInterface $messages,
        private ChatAvailabilityPolicy $availability,
        private MarkdownRenderer $markdown,
        private CurrentAdmin $currentAdmin,
        private ClockInterface $clock,
        private ChatParams $params,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $reason = $this->availability->getUnavailableReason($knowledgeBase);
        $chatReady = $reason->isAvailable();
        $participant = ChatParticipant::admin($this->currentAdmin->get()->id());
        $conversation = $this->threads->find($knowledgeBase->id(), $participant);
        $messages = [];
        $hasOlder = false;
        $editView = MessageEditView::none();

        if ($conversation !== null) {
            $messages = $this->messages->findRecentByConversation(
                $conversation->id,
                ChatThreadParams::RECENT_MESSAGE_LIMIT,
            );
            $total = $this->messages->countByConversation($conversation->id);
            $hasOlder = $total > count($messages);
            $editView = MessageEditView::compute(
                $this->messages,
                $conversation->id,
                $messages,
                $chatReady,
                $this->clock->now(),
                $this->params->editWindowMinutes,
            );
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'knowledgeBase' => $knowledgeBase,
                'conversation' => $conversation,
                'messages' => $messages,
                'hasOlder' => $hasOlder,
                'chatReady' => $chatReady,
                'provisioned' => $knowledgeBase->isReadyForChat(),
                'unavailableMessage' => $reason->message(),
                'markdown' => $this->markdown,
                'editView' => $editView,
                'maxQuestionLength' => $this->params->maxQuestionLength,
            ]);
    }
}
