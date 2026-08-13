<?php

declare(strict_types=1);

namespace App\Agent\Web\Chat;

use App\Agent\Application\CurrentAgent;
use App\Chat\Application\ShowChatSourceService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ChatRetrievalScope;
use App\Chat\Web\ChatSourcePayload;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * One cited source of an answer in an agent's store chat, as JSON
 * (GET /agent/stores/{slug}/chat/{conversationId}/messages/{messageId}/source/{documentId}).
 *
 * Two independent gates, as everywhere in the agent realm: {@see AgentStoreResolver} for the store — a store
 * this agent may not chat with is a 404 here too, and its existence is never revealed — and, inside
 * {@see ShowChatSourceService}, the agent-typed thread lookup, so one agent can never read the sources of
 * another agent's answer even holding the right ids.
 */
final readonly class ShowSourceAction
{
    public function __construct(
        private ShowChatSourceService $sources,
        private AgentStoreResolver $resolver,
        private CurrentAgent $currentAgent,
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
            $this->resolver->resolve($slug),
            ChatParticipant::agent($this->currentAgent->get()->adminId),
            $conversationId,
            $messageId,
            $documentId,
            ChatRetrievalScope::StoreKnowledge,
        ));
    }
}
