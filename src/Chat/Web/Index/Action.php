<?php

declare(strict_types=1);

namespace App\Chat\Web\Index;

use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Web\ChatThreadParams;
use App\Document\Domain\DocumentRepositoryInterface;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
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
        private DocumentRepositoryInterface $documents,
        private MarkdownRenderer $markdown,
        private CurrentAdmin $currentAdmin,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $readyDocuments = $this->documents->countReadyForKnowledgeBase($knowledgeBase->id());
        $participant = ChatParticipant::admin($this->currentAdmin->get()->id());
        $conversation = $this->threads->find($knowledgeBase->id(), $participant);
        $messages = [];
        $hasOlder = false;

        if ($conversation !== null) {
            $messages = $this->messages->findRecentByConversation(
                $conversation->id,
                ChatThreadParams::RECENT_MESSAGE_LIMIT,
            );
            $total = $this->messages->countByConversation($conversation->id);
            $hasOlder = $total > count($messages);
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'knowledgeBase' => $knowledgeBase,
                'conversation' => $conversation,
                'messages' => $messages,
                'hasOlder' => $hasOlder,
                'chatReady' => $knowledgeBase->isReadyForChat() && $readyDocuments > 0,
                'provisioned' => $knowledgeBase->isReadyForChat(),
                'markdown' => $this->markdown,
            ]);
    }
}
