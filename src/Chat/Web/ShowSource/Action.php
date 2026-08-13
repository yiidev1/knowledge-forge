<?php

declare(strict_types=1);

namespace App\Chat\Web\ShowSource;

use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\ShowChatSourceService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ChatRetrievalScope;
use App\Chat\Web\ChatSourcePayload;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * One cited source of an answer in an admin store chat, as JSON
 * (GET /knowledge-bases/{slug}/chat/{conversationId}/messages/{messageId}/source/{documentId}).
 *
 * Thin on purpose: the knowledge base comes from the same {@see KnowledgeBaseFinder} the chat page uses and
 * the participant from the session, never the request body. Every other check — ownership, the message, the
 * citation gate, scope and Store Profile visibility — lives in {@see ShowChatSourceService}, which is the
 * security boundary. The retrieval scope is fixed by the route, so a store chat can only ever reach store
 * knowledge.
 */
final readonly class Action
{
    public function __construct(
        private ShowChatSourceService $sources,
        private KnowledgeBaseFinder $finder,
        private CurrentAdmin $currentAdmin,
        private ChatSourcePayload $payload,
    ) {}

    public function __invoke(
        #[RouteArgument]
        string $slug,
        #[RouteArgument]
        int $conversationId,
        #[RouteArgument]
        int $messageId,
        #[RouteArgument]
        int $documentId,
    ): ResponseInterface {
        return $this->payload->respond($this->sources->detailFor(
            $this->finder->bySlug($slug),
            ChatParticipant::admin($this->currentAdmin->get()->id()),
            $conversationId,
            $messageId,
            $documentId,
            ChatRetrievalScope::StoreKnowledge,
        ));
    }
}
