<?php

declare(strict_types=1);

namespace App\Agent\Web\Chat;

use App\Agent\Application\CurrentAgent;
use App\Agent\Domain\AgentConversationRepositoryInterface;
use App\Ai\Contract\Exception\AiException;
use App\Chat\Application\AskKnowledgeBaseService;
use App\Chat\Application\ChatParams;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\QuestionInvalid;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Session\SessionInterface;

use function mb_strlen;
use function mb_substr;
use function trim;

/**
 * Starts a new conversation for an agent against one store (POST /agent/stores/{slug}/chat).
 *
 * The conversation is created bound to the agent, then the existing ask service answers the first question
 * — the one synchronous OpenAI call, against that store's vector store only. The question is validated
 * before the conversation is created, so an empty or over-long submission never leaves a stray thread.
 */
final readonly class StartAction
{
    private const TITLE_MAX = 80;

    public function __construct(
        private AskKnowledgeBaseService $askService,
        private AgentStoreResolver $resolver,
        private AgentConversationRepositoryInterface $conversations,
        private CurrentAgent $currentAgent,
        private ChatParams $params,
        private ClockInterface $clock,
        private Redirect $redirect,
        private FlashMessages $flash,
        private SessionInterface $session,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, ServerRequestInterface $request): ResponseInterface
    {
        $knowledgeBase = $this->resolver->resolve($slug);
        $question = trim(FormData::fromRequest($request)->raw('question'));

        if ($question === '') {
            $this->flash->error('Enter a question.');

            return $this->redirect->afterPost('agent.chat.index', ['slug' => $slug]);
        }
        if (mb_strlen($question) > $this->params->maxQuestionLength) {
            $this->flash->error('Your question is too long.');

            return $this->redirect->afterPost('agent.chat.index', ['slug' => $slug]);
        }

        $conversationId = $this->conversations->create(
            $knowledgeBase->id(),
            $this->currentAgent->get()->adminId,
            $this->titleFrom($question),
            $this->clock->now(),
        );

        // Release the session lock before the long provider call (see the admin chat action for why).
        $this->session->close();

        try {
            $this->askService->ask($knowledgeBase, $conversationId, $question);
        } catch (QuestionInvalid|ChatUnavailable $e) {
            $this->flash->error($e->getMessage());
        } catch (AiException) {
            $this->flash->error('The assistant is temporarily unavailable. Please try again in a moment.');
        }

        return $this->redirect->afterPost('agent.chat.show', ['slug' => $slug, 'conversationId' => $conversationId]);
    }

    private function titleFrom(string $question): string
    {
        $title = trim(mb_substr($question, 0, self::TITLE_MAX));

        return $title === '' ? 'Untitled conversation' : $title;
    }
}
