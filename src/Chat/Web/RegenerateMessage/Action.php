<?php

declare(strict_types=1);

namespace App\Chat\Web\RegenerateMessage;

use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\EditChatMessageService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\MessageEditException;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Session\SessionInterface;

/**
 * Retries a failed answer regeneration for this admin's latest question
 * (POST /knowledge-bases/{slug}/chat/{conversationId}/messages/{messageId}/regenerate).
 *
 * Idempotent: if the question already has an active answer the service no-ops. Ownership is resolved
 * server-side; a forged id is a 404.
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
    ): ResponseInterface {
        $knowledgeBase = $this->finder->bySlug($slug);
        $participant = ChatParticipant::admin($this->currentAdmin->get()->id());

        $this->session->close();

        try {
            $outcome = $this->editService->regenerateAnswer(
                $knowledgeBase,
                $participant,
                $conversationId,
                $messageId,
            );
            if (!$outcome->answerRegenerated) {
                $this->flash->error('The answer still could not be generated. Please try again in a moment.');
            }
        } catch (ChatUnavailable|MessageEditException $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('chat.index', ['slug' => $slug]);
    }
}
