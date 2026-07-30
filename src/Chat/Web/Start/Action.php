<?php

declare(strict_types=1);

namespace App\Chat\Web\Start;

use App\Ai\Contract\Exception\AiException;
use App\Chat\Application\AskKnowledgeBaseService;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\QuestionInvalid;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Session\SessionInterface;

/**
 * Starts a new conversation from a first question (POST /knowledge-bases/{slug}/chat).
 *
 * The synchronous OpenAI call happens here. A validation or availability problem returns to the chat
 * index with a message; a provider failure does too (the assistant is temporarily unavailable). On
 * success the user lands on the new conversation.
 */
final readonly class Action
{
    public function __construct(
        private AskKnowledgeBaseService $askService,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
        private SessionInterface $session,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, ServerRequestInterface $request): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $question = FormData::fromRequest($request)->raw('question');

        // Release the session file lock before the long provider call — see Chat\Web\Ask\Action for the
        // full reasoning. Without it, a second tab in the same logged-in browser blocks for the whole
        // OpenAI round trip.
        $this->session->close();

        try {
            $conversationId = $this->askService->startConversation($knowledgeBase, $question);
        } catch (QuestionInvalid|ChatUnavailable $e) {
            $this->flash->error($e->getMessage());

            return $this->redirect->afterPost('chat.index', ['slug' => $slug]);
        } catch (AiException) {
            $this->flash->error('The assistant is temporarily unavailable. Please try again in a moment.');

            return $this->redirect->afterPost('chat.index', ['slug' => $slug]);
        }

        return $this->redirect->afterPost('chat.show', ['slug' => $slug, 'conversationId' => $conversationId]);
    }
}
