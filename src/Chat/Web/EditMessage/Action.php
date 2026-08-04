<?php

declare(strict_types=1);

namespace App\Chat\Web\EditMessage;

use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\EditChatMessageService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\MessageEditException;
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
 * Edits this admin's latest question and regenerates its answer
 * (POST /knowledge-bases/{slug}/chat/{conversationId}/messages/{messageId}/edit).
 *
 * The participant is resolved from the session, never the request body; a forged conversation or message id
 * is a 404 via {@see \App\Chat\Domain\Exception\MessageNotFound}. Recoverable problems (window closed, stale
 * edit, unchanged, not the latest) flash and redirect (PRG). Regeneration runs synchronously, so the
 * session is closed first, exactly like Ask.
 */
final readonly class Action
{
    public function __construct(
        private EditChatMessageService $editService,
        private KnowledgeBaseFinder $finder,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private FlashMessages $flash,
        private SessionInterface $session,
    ) {}

    public function __invoke(
        #[RouteArgument]
        string $slug,
        #[RouteArgument]
        int $conversationId,
        #[RouteArgument]
        int $messageId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $knowledgeBase = $this->finder->bySlug($slug);
        $participant = ChatParticipant::admin($this->currentAdmin->get()->id());

        $form = FormData::fromRequest($request);
        $content = $form->raw('content');
        $expectedEditCount = (int) $form->string('expected_edit_count');

        $this->session->close();

        try {
            $outcome = $this->editService->edit(
                $knowledgeBase,
                $participant,
                $conversationId,
                $messageId,
                $content,
                $expectedEditCount,
            );
            if (!$outcome->answerRegenerated) {
                $this->flash->error('Your question was updated, but the answer could not be regenerated. Use Retry to try again.');
            }
        } catch (QuestionInvalid|ChatUnavailable|MessageEditException $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('chat.index', ['slug' => $slug]);
    }
}
