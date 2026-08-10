<?php

declare(strict_types=1);

namespace App\Chat\Web\RuleChat\Show;

use App\Chat\Application\RuleChatKnowledgeBaseResolver;
use App\Chat\Domain\Exception\ConversationNotFound;
use App\Chat\Web\ConversationFinder;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/** Legacy Rule Chat conversation URL → canonical Rule Chat index when owned; otherwise 404. */
final readonly class Action
{
    public function __construct(
        private RuleChatKnowledgeBaseResolver $resolver,
        private ConversationFinder $conversations,
        private Redirect $redirect,
    ) {}

    public function __invoke(#[RouteArgument] int $conversationId): ResponseInterface
    {
        $knowledgeBase = $this->resolver->find();
        if ($knowledgeBase === null) {
            throw ConversationNotFound::inKnowledgeBase($conversationId, 0);
        }

        $this->conversations->forKnowledgeBase($conversationId, $knowledgeBase->id());

        return $this->redirect->toRoute('admin.rule-chat.index');
    }
}
