<?php

declare(strict_types=1);

namespace App\Agent\Web\RuleChat;

use App\Agent\Application\CurrentAgent;
use App\Chat\Application\EditChatMessageService;
use App\Chat\Application\RuleChatKnowledgeBaseResolver;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ChatRetrievalScope;
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

/** Edits this agent's latest Rule Chat question and regenerates its answer. */
final readonly class EditMessageAction
{
    public function __construct(
        private EditChatMessageService $editService,
        private RuleChatKnowledgeBaseResolver $resolver,
        private CurrentAgent $currentAgent,
        private Redirect $redirect,
        private FlashMessages $flash,
        private SessionInterface $session,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $conversationId,
        #[RouteArgument]
        int $messageId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $participant = ChatParticipant::agent($this->currentAgent->get()->adminId);
        $form = FormData::fromRequest($request);
        $content = $form->raw('content');
        $expectedEditCount = (int) $form->string('expected_edit_count');

        $this->session->close();

        try {
            $knowledgeBase = $this->resolver->requireReady();
            $outcome = $this->editService->edit(
                $knowledgeBase,
                $participant,
                $conversationId,
                $messageId,
                $content,
                $expectedEditCount,
                ChatRetrievalScope::RuleOnly,
            );
            if (!$outcome->answerRegenerated) {
                $this->flash->error('Your question was updated, but the answer could not be regenerated. Use Retry to try again.');
            }
        } catch (QuestionInvalid|ChatUnavailable|MessageEditException $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('agent.rule-chat.index');
    }
}
