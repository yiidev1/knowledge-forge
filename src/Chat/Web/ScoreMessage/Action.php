<?php

declare(strict_types=1);

namespace App\Chat\Web\ScoreMessage;

use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\ScoreChatAnswerService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\AnswerScoreInvalid;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Records this admin's rating of an answer in a store chat
 * (POST /knowledge-bases/{slug}/chat/{conversationId}/messages/{messageId}/score).
 *
 * The participant comes from the session, never the body, so a forged conversation or message id is a 404.
 * No provider call happens here — scoring is feedback about an answer that already exists.
 */
final readonly class Action
{
    public function __construct(
        private ScoreChatAnswerService $scoreService,
        private KnowledgeBaseFinder $finder,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private FlashMessages $flash,
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

        try {
            $this->scoreService->score(
                $knowledgeBase,
                $participant,
                $conversationId,
                $messageId,
                FormData::fromRequest($request)->rawValue('score'),
                FormData::fromRequest($request)->rawValue('feedback_comment'),
            );
        } catch (AnswerScoreInvalid $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('chat.index', ['slug' => $slug]);
    }
}
