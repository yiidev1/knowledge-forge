<?php

declare(strict_types=1);

namespace App\Chat\Web\RuleChat\RegenerateMessage;

use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\EditChatMessageService;
use App\Chat\Application\RuleChatKnowledgeBaseResolver;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ChatRetrievalScope;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\MessageEditException;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Session\SessionInterface;

/** Retries a failed Rule Chat answer regeneration for this admin. */
final readonly class Action
{
    public function __construct(
        private EditChatMessageService $editService,
        private RuleChatKnowledgeBaseResolver $resolver,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private FlashMessages $flash,
        private SessionInterface $session,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $conversationId,
        #[RouteArgument]
        int $messageId,
    ): ResponseInterface {
        $participant = ChatParticipant::admin($this->currentAdmin->get()->id());

        $this->session->close();

        try {
            $knowledgeBase = $this->resolver->requireReady();
            $outcome = $this->editService->regenerateAnswer(
                $knowledgeBase,
                $participant,
                $conversationId,
                $messageId,
                ChatRetrievalScope::RuleOnly,
            );
            if (!$outcome->answerRegenerated) {
                $this->flash->error('The answer still could not be generated. Please try again in a moment.');
            }
        } catch (ChatUnavailable|MessageEditException $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('admin.rule-chat.index');
    }
}
