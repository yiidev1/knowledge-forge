<?php

declare(strict_types=1);

namespace App\Agent\Web\RuleChat;

use App\Agent\Application\CurrentAgent;
use App\Agent\Domain\AgentConversationRepositoryInterface;
use App\Ai\Contract\Exception\AiException;
use App\Chat\Application\AskKnowledgeBaseService;
use App\Chat\Application\RuleChatKnowledgeBaseResolver;
use App\Chat\Domain\ChatRetrievalScope;
use App\Chat\Domain\Conversation;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\ConversationNotFound;
use App\Chat\Domain\Exception\QuestionInvalid;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Session\SessionInterface;

/** Legacy follow-up POST with conversation id for Agent Rule Chat. */
final readonly class AskAction
{
    public function __construct(
        private AskKnowledgeBaseService $askService,
        private RuleChatKnowledgeBaseResolver $resolver,
        private AgentConversationRepositoryInterface $conversations,
        private CurrentAgent $currentAgent,
        private Redirect $redirect,
        private FlashMessages $flash,
        private SessionInterface $session,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $conversationId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $question = FormData::fromRequest($request)->raw('question');
        $this->session->close();

        try {
            $knowledgeBase = $this->resolver->requireReady();
            $conversation = $this->conversations->findForAgent(
                $conversationId,
                $knowledgeBase->id(),
                $this->currentAgent->get()->adminId,
            );
            if (!$conversation instanceof Conversation) {
                throw ConversationNotFound::inKnowledgeBase($conversationId, $knowledgeBase->id());
            }
            $this->askService->ask(
                $knowledgeBase,
                $conversation->id,
                $question,
                ChatRetrievalScope::RuleOnly,
            );
        } catch (QuestionInvalid|ChatUnavailable $e) {
            $this->flash->error($e->getMessage());
        } catch (AiException) {
            $this->flash->error('The assistant is temporarily unavailable. Please try again in a moment.');
        }

        return $this->redirect->afterPost('agent.rule-chat.index');
    }
}
