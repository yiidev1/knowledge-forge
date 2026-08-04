<?php

declare(strict_types=1);

namespace App\Chat\Web\Start;

use App\Ai\Contract\Exception\AiException;
use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\AskKnowledgeBaseService;
use App\Chat\Domain\ChatParticipant;
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
 * Sends a question on this admin's canonical thread (POST /knowledge-bases/{slug}/chat).
 */
final readonly class Action
{
    public function __construct(
        private AskKnowledgeBaseService $askService,
        private KnowledgeBaseFinder $finder,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private FlashMessages $flash,
        private SessionInterface $session,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, ServerRequestInterface $request): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $question = FormData::fromRequest($request)->raw('question');
        $participant = ChatParticipant::admin($this->currentAdmin->get()->id());

        $this->session->close();

        try {
            $this->askService->startConversation($knowledgeBase, $question, $participant);
        } catch (QuestionInvalid|ChatUnavailable $e) {
            $this->flash->error($e->getMessage());
        } catch (AiException) {
            $this->flash->error('The assistant is temporarily unavailable. Please try again in a moment.');
        }

        return $this->redirect->afterPost('chat.index', ['slug' => $slug]);
    }
}
