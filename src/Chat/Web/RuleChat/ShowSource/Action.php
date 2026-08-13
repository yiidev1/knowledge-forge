<?php

declare(strict_types=1);

namespace App\Chat\Web\RuleChat\ShowSource;

use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\RuleChatKnowledgeBaseResolver;
use App\Chat\Application\ShowChatSourceService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ChatRetrievalScope;
use App\Chat\Domain\Exception\ConversationNotFound;
use App\Chat\Web\ChatSourcePayload;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * One cited source of an answer in the admin Rule Chat, as JSON
 * (GET /admin/rule-chat/{conversationId}/messages/{messageId}/source/{documentId}).
 *
 * Uses `find()` rather than `requireReady()`, matching the scoring actions: reading the source of an answer
 * that already exists must keep working even if the rule corpus has since stopped being answerable.
 * {@see ChatRetrievalScope::RuleOnly} is fixed here, so this surface can only ever reach rule projections.
 */
final readonly class Action
{
    public function __construct(
        private ShowChatSourceService $sources,
        private RuleChatKnowledgeBaseResolver $resolver,
        private CurrentAdmin $currentAdmin,
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
            ChatParticipant::admin($this->currentAdmin->get()->id()),
            $conversationId,
            $messageId,
            $documentId,
            ChatRetrievalScope::RuleOnly,
        ));
    }
}
