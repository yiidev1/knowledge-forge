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
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Records this agent's rating of a Rule Chat answer
 * (POST /agent/rule-chat/{conversationId}/messages/{messageId}/score).
 *
 * The rules base is shared, but the thread is not: the lookup is scoped to this agent, so an agent can only
 * ever score answers from their own private Rule Chat.
 */
final readonly class ScoreMessageAction
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
        ServerRequestInterface $request,
    ): ResponseInterface {
        $knowledgeBase = $this->resolver->find();
        if ($knowledgeBase === null) {
            throw ConversationNotFound::inKnowledgeBase($conversationId, 0);
        }

        try {
            $this->scoreService->score(
                $knowledgeBase,
                ChatParticipant::agent($this->currentAgent->get()->adminId),
                $conversationId,
                $messageId,
                FormData::fromRequest($request)->rawValue('score'),
                FormData::fromRequest($request)->rawValue('feedback_comment'),
            );
        } catch (AnswerScoreInvalid $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('agent.rule-chat.index');
    }
}
