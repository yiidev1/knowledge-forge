<?php

declare(strict_types=1);

namespace App\Chat\Web\Show;

use App\Chat\Web\ConversationFinder;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Legacy conversation URL (GET /knowledge-bases/{slug}/chat/{conversationId}).
 * Redirects to the slug chat when the id is this admin's thread for the KB; otherwise 404.
 */
final readonly class Action
{
    public function __construct(
        private KnowledgeBaseFinder $finder,
        private ConversationFinder $conversations,
        private Redirect $redirect,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, #[RouteArgument] int $conversationId): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        // Throws ConversationNotFound → 404 when not owned by the current admin for this KB.
        $this->conversations->forKnowledgeBase($conversationId, $knowledgeBase->id());

        return $this->redirect->toRoute('chat.index', ['slug' => $slug]);
    }
}
