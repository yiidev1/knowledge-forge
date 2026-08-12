<?php

declare(strict_types=1);

namespace App\Agent\Web\RuleChat;

use App\Agent\Application\CurrentAgent;
use App\Chat\Application\RuleChatKnowledgeBaseResolver;
use App\Chat\Application\ScoreChatAnswerService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\AnswerScoreInvalid;
use App\Chat\Domain\Exception\ConversationNotFound;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Records that this agent declined to rate a Rule Chat answer
 * (POST /agent/rule-chat/{conversationId}/messages/{messageId}/dismiss-score).
 */
final readonly class DismissScoreAction
{
    public function __construct(
        private ScoreChatAnswerService $scoreService,
        private RuleChatKnowledgeBaseResolver $resolver,
        private CurrentAgent $currentAgent,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $conversationId,
        #[RouteArgument]
        int $messageId,
    ): ResponseInterface {
        $knowledgeBase = $this->resolver->find();
        if ($knowledgeBase === null) {
            throw ConversationNotFound::inKnowledgeBase($conversationId, 0);
        }

        try {
            $this->scoreService->dismiss(
                $knowledgeBase,
                ChatParticipant::agent($this->currentAgent->get()->adminId),
                $conversationId,
                $messageId,
            );
        } catch (AnswerScoreInvalid $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('agent.rule-chat.index');
    }
}
