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
 * Legacy follow-up POST with conversation id. Only this admin's thread is accepted; then redirect
 * to the slug chat page.
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

        $this->session->close();

        try {
            $this->askService->ask($knowledgeBase, $conversation->id, $question);
        } catch (QuestionInvalid|ChatUnavailable $e) {
            $this->flash->error($e->getMessage());
        } catch (AiException) {
            $this->flash->error('The assistant is temporarily unavailable. Please try again in a moment.');
        }

        return $this->redirect->afterPost('chat.index', ['slug' => $slug]);
    }
}
