<?php

declare(strict_types=1);

namespace App\Chat\Web\RuleChat\Start;

use App\Ai\Contract\Exception\AiException;
use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\AskKnowledgeBaseService;
use App\Chat\Application\RuleChatKnowledgeBaseResolver;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ChatRetrievalScope;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\QuestionInvalid;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Session\SessionInterface;

/** POST /admin/rule-chat — first or follow-up question on this admin's Rule Chat thread. */
final readonly class Action
{
    public function __construct(
        private AskKnowledgeBaseService $askService,
        private RuleChatKnowledgeBaseResolver $resolver,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private FlashMessages $flash,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $question = FormData::fromRequest($request)->raw('question');
        $participant = ChatParticipant::admin($this->currentAdmin->get()->id());

        $this->session->close();

        try {
            $knowledgeBase = $this->resolver->requireReady();
            $this->askService->startConversation(
                $knowledgeBase,
                $question,
                $participant,
                ChatRetrievalScope::RuleOnly,
            );
        } catch (QuestionInvalid|ChatUnavailable $e) {
            $this->flash->error($e->getMessage());
        } catch (AiException) {
            $this->flash->error('The assistant is temporarily unavailable. Please try again in a moment.');
        }

        return $this->redirect->afterPost('admin.rule-chat.index');
    }
}
