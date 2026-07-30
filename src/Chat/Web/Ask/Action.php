<?php

declare(strict_types=1);

namespace App\Chat\Web\Ask;

use App\Ai\Contract\Exception\AiException;
use App\Chat\Application\AskKnowledgeBaseService;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\QuestionInvalid;
use App\Chat\Web\ConversationFinder;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Session\SessionInterface;

/**
 * Asks a follow-up question within a conversation (POST /knowledge-bases/{slug}/chat/{conversationId}).
 *
 * The conversation is resolved scoped to the knowledge base (a foreign id yields a 404). Errors return
 * to the same conversation with a message; on success the thread reloads with the new answer.
 */
final readonly class Action
{
    public function __construct(
        private AskKnowledgeBaseService $askService,
        private KnowledgeBaseFinder $finder,
        private ConversationFinder $conversations,
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
        $knowledgeBase = $this->finder->bySlug($slug);
        $conversation = $this->conversations->forKnowledgeBase($conversationId, $knowledgeBase->id());
        $question = FormData::fromRequest($request)->raw('question');

        // Release the session file lock before the long provider call.
        //
        // PHP's `files` handler holds an exclusive lock from session_start() until the session is written,
        // and CsrfTokenMiddleware opens the session before the router even runs. Without this the lock is
        // held for the entire OpenAI round trip, so every other request from the same logged-in browser
        // queues behind it and a second tab appears to hang.
        //
        // Safe here: authentication and CSRF validation have already read what they need, and close()
        // keeps the session id, so the flash writes below and the CSRF token used at render time reopen
        // it transparently. The id is unchanged, so no new cookie is issued and the login survives.
        $this->session->close();

        try {
            $this->askService->ask($knowledgeBase, $conversation->id, $question);
        } catch (QuestionInvalid|ChatUnavailable $e) {
            $this->flash->error($e->getMessage());
        } catch (AiException) {
            $this->flash->error('The assistant is temporarily unavailable. Please try again in a moment.');
        }

        return $this->redirect->afterPost('chat.show', ['slug' => $slug, 'conversationId' => $conversation->id]);
    }
}
