<?php

declare(strict_types=1);

namespace App\Chat\Web\ScoreMessage;

use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\ScoreChatAnswerService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\AnswerScoreInvalid;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Records that this admin declined to rate an answer in a store chat
 * (POST /knowledge-bases/{slug}/chat/{conversationId}/messages/{messageId}/dismiss-score).
 *
 * Dismissing hides the prompt, nothing else: the answer stays, and no score is written — a decline is not a
 * zero. A dismissal aimed at an already-rated answer is refused so it cannot discard the score.
 */
final readonly class DismissAction
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
    ): ResponseInterface {
        $knowledgeBase = $this->finder->bySlug($slug);
        $participant = ChatParticipant::admin($this->currentAdmin->get()->id());

        try {
            $this->scoreService->dismiss($knowledgeBase, $participant, $conversationId, $messageId);
        } catch (AnswerScoreInvalid $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('chat.index', ['slug' => $slug]);
    }
}
