<?php

declare(strict_types=1);

namespace App\Agent\Web\Chat;

use App\Agent\Application\CurrentAgent;
use App\Chat\Application\ScoreChatAnswerService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\AnswerScoreInvalid;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Records that this agent declined to rate an answer in their store chat
 * (POST /agent/stores/{slug}/chat/{conversationId}/messages/{messageId}/dismiss-score).
 */
final readonly class DismissScoreAction
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
    ): ResponseInterface {
        $knowledgeBase = $this->resolver->resolve($slug);

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

        return $this->redirect->afterPost('agent.chat.index', ['slug' => $slug]);
    }
}
