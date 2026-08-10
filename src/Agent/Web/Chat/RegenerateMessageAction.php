<?php

declare(strict_types=1);

namespace App\Agent\Web\Chat;

use App\Agent\Application\CurrentAgent;
use App\Chat\Application\EditChatMessageService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\MessageEditException;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Session\SessionInterface;

/**
 * Retries a failed answer regeneration for this agent's latest question
 * (POST /agent/stores/{slug}/chat/{conversationId}/messages/{messageId}/regenerate). Idempotent; ownership
 * resolved server-side.
 */
final readonly class RegenerateMessageAction
{
    public function __construct(
        private EditChatMessageService $editService,
        private AgentStoreResolver $resolver,
        private CurrentAgent $currentAgent,
        private Redirect $redirect,
        private FlashMessages $flash,
        private SessionInterface $session,
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
        $participant = ChatParticipant::agent($this->currentAgent->get()->adminId);

        $this->session->close();

        try {
            $outcome = $this->editService->regenerateAnswer(
                $knowledgeBase,
                $participant,
                $conversationId,
                $messageId,
            );
            if (!$outcome->answerRegenerated) {
                $this->flash->error('The answer still could not be generated. Please try again in a moment.');
            }
        } catch (ChatUnavailable|MessageEditException $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('agent.chat.index', ['slug' => $slug]);
    }
}
