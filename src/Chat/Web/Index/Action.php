<?php

declare(strict_types=1);

namespace App\Chat\Web\Index;

use App\Chat\Domain\ConversationRepositoryInterface;
use App\Document\Domain\DocumentRepositoryInterface;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Chat home for a knowledge base (GET /knowledge-bases/{slug}/chat): the conversation list and a box to
 * start a new one. When the base is not ready for chat (still provisioning, or no indexed documents),
 * the form is replaced with an explanation instead.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private KnowledgeBaseFinder $finder,
        private ConversationRepositoryInterface $conversations,
        private DocumentRepositoryInterface $documents,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $readyDocuments = $this->documents->countReadyForKnowledgeBase($knowledgeBase->id());

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'knowledgeBase' => $knowledgeBase,
                'conversations' => $this->conversations->findAllForKnowledgeBase($knowledgeBase->id()),
                'chatReady' => $knowledgeBase->isReadyForChat() && $readyDocuments > 0,
                'provisioned' => $knowledgeBase->isReadyForChat(),
                'readyDocuments' => $readyDocuments,
            ]);
    }
}
