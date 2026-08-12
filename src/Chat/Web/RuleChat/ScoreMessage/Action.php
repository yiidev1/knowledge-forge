<?php

declare(strict_types=1);

namespace App\Chat\Web\RuleChat\ScoreMessage;

use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\RuleChatKnowledgeBaseResolver;
use App\Chat\Application\ScoreChatAnswerService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\AnswerScoreInvalid;
use App\Chat\Domain\Exception\ConversationNotFound;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Records this admin's rating of a Rule Chat answer
 * (POST /admin/rule-chat/{conversationId}/messages/{messageId}/score).
 *
 * Uses {@see RuleChatKnowledgeBaseResolver::find()} rather than `requireReady()`: rating is about an answer
 * that already exists, so it must keep working even after the rules base stops being answerable. When the
 * base has never been created there is nothing to score, and the thread lookup 404s.
 */
final readonly class Action
{
    public function __construct(
        private ScoreChatAnswerService $scoreService,
        private RuleChatKnowledgeBaseResolver $resolver,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $conversationId,
        #[RouteArgument]
        int $messageId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $knowledgeBase = $this->resolver->find();
        if ($knowledgeBase === null) {
            throw ConversationNotFound::inKnowledgeBase($conversationId, 0);
        }

        try {
            $this->scoreService->score(
                $knowledgeBase,
                ChatParticipant::admin($this->currentAdmin->get()->id()),
                $conversationId,
                $messageId,
                FormData::fromRequest($request)->rawValue('score'),
            );
        } catch (AnswerScoreInvalid $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('admin.rule-chat.index');
    }
}
