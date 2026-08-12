<?php

declare(strict_types=1);

namespace App\Agent\Web\Chat;

use App\Agent\Application\CurrentAgent;
use App\Chat\Application\ScoreChatAnswerService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\AnswerScoreInvalid;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Records this agent's rating of an answer in their store chat
 * (POST /agent/stores/{slug}/chat/{conversationId}/messages/{messageId}/score).
 *
 * Same service and guarantees as the admin surface; only the participant and the store gate differ. Both
 * agent gates still apply: {@see AgentStoreResolver} for the store, and the thread lookup for ownership —
 * one agent can never score another agent's answer even with the right message id.
 */
final readonly class ScoreMessageAction
{
    public function __construct(
        private ScoreChatAnswerService $scoreService,
        private AgentStoreResolver $resolver,
        private CurrentAgent $currentAgent,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(
        #[RouteArgument]
        string $slug,
        #[RouteArgument]
        int $conversationId,
        #[RouteArgument]
        int $messageId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $knowledgeBase = $this->resolver->resolve($slug);

        try {
            $this->scoreService->score(
                $knowledgeBase,
                ChatParticipant::agent($this->currentAgent->get()->adminId),
                $conversationId,
                $messageId,
                FormData::fromRequest($request)->rawValue('score'),
            );
        } catch (AnswerScoreInvalid $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('agent.chat.index', ['slug' => $slug]);
    }
}
