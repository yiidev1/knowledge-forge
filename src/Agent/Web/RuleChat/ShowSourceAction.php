<?php

declare(strict_types=1);

namespace App\Agent\Web\RuleChat;

use App\Agent\Application\CurrentAgent;
use App\Chat\Application\RuleChatKnowledgeBaseResolver;
use App\Chat\Application\ShowChatSourceService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ChatRetrievalScope;
use App\Chat\Domain\Exception\ConversationNotFound;
use App\Chat\Web\ChatSourcePayload;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * One cited source of an agent Rule Chat answer, as JSON
 * (GET /agent/rule-chat/{conversationId}/messages/{messageId}/source/{documentId}).
 *
 * The rules base is shared between agents; the thread is not. The agent-typed thread lookup inside
 * {@see ShowChatSourceService} is what keeps one agent out of another's conversation, and
 * {@see ChatRetrievalScope::RuleOnly} keeps this surface inside the rule corpus.
 */
final readonly class ShowSourceAction
{
    public function __construct(
        private ShowChatSourceService $sources,
        private RuleChatKnowledgeBaseResolver $resolver,
        private CurrentAgent $currentAgent,
        private ChatSourcePayload $payload,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $conversationId,
        #[RouteArgument]
        int $messageId,
        #[RouteArgument]
        int $documentId,
    ): ResponseInterface {
        $knowledgeBase = $this->resolver->find();
        if ($knowledgeBase === null) {
            throw ConversationNotFound::inKnowledgeBase($conversationId, 0);
        }

        return $this->payload->respond($this->sources->detailFor(
            $knowledgeBase,
            ChatParticipant::agent($this->currentAgent->get()->adminId),
            $conversationId,
            $messageId,
            $documentId,
            ChatRetrievalScope::RuleOnly,
        ));
    }
}
