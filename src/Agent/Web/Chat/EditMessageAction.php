<?php

declare(strict_types=1);

namespace App\Agent\Web\Chat;

use App\Agent\Application\CurrentAgent;
use App\Chat\Application\EditChatMessageService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\MessageEditException;
use App\Chat\Domain\Exception\QuestionInvalid;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Session\SessionInterface;

/**
 * Edits this agent's latest question in a store thread and regenerates its answer
 * (POST /agent/stores/{slug}/chat/{conversationId}/messages/{messageId}/edit).
 *
 * Same service and guarantees as the admin surface; only the participant (agent, resolved from the session)
 * and store gating ({@see AgentStoreResolver}) differ. A store the agent may not reach, or a conversation
 * owned by someone else, is a 404.
 */
final readonly class EditMessageAction
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
        ServerRequestInterface $request,
    ): ResponseInterface {
        $knowledgeBase = $this->resolver->resolve($slug);
        $participant = ChatParticipant::agent($this->currentAgent->get()->adminId);

        $form = FormData::fromRequest($request);
        $content = $form->raw('content');
        $expectedEditCount = (int) $form->string('expected_edit_count');

        $this->session->close();

        try {
            $outcome = $this->editService->edit(
                $knowledgeBase,
                $participant,
                $conversationId,
                $messageId,
                $content,
                $expectedEditCount,
            );
            if (!$outcome->answerRegenerated) {
                $this->flash->error('Your question was updated, but the answer could not be regenerated. Use Retry to try again.');
            }
        } catch (QuestionInvalid|ChatUnavailable|MessageEditException $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('agent.chat.index', ['slug' => $slug]);
    }
}
