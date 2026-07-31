<?php

declare(strict_types=1);

namespace App\Agent\Web\Chat;

use App\Agent\Application\CurrentAgent;
use App\Agent\Domain\AgentConversationRepositoryInterface;
use App\Ai\Contract\Exception\AiException;
use App\Chat\Application\AskKnowledgeBaseService;
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

/**
 * A follow-up question within an agent conversation (POST /agent/stores/{slug}/chat/{conversationId}).
 * Retrieval stays bound to this store's vector store; the conversation can never switch stores.
 */
final readonly class AskAction
{
    public function __construct(
        private AskKnowledgeBaseService $askService,
        private AgentStoreResolver $resolver,
        private AgentConversationRepositoryInterface $conversations,
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
        ServerRequestInterface $request,
    ): ResponseInterface {
        $knowledgeBase = $this->resolver->resolve($slug);
        $conversation = $this->conversations->findForAgent(
            $conversationId,
            $knowledgeBase->id(),
            $this->currentAgent->get()->adminId,
        );

        if (!$conversation instanceof Conversation) {
            throw ConversationNotFound::inKnowledgeBase($conversationId, $knowledgeBase->id());
        }

        $question = FormData::fromRequest($request)->raw('question');

        $this->session->close();

        try {
            $this->askService->ask($knowledgeBase, $conversation->id, $question);
        } catch (QuestionInvalid|ChatUnavailable $e) {
            $this->flash->error($e->getMessage());
        } catch (AiException) {
            $this->flash->error('The assistant is temporarily unavailable. Please try again in a moment.');
        }

        return $this->redirect->afterPost('agent.chat.show', ['slug' => $slug, 'conversationId' => $conversation->id]);
    }
}
