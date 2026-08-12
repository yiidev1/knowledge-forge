<?php

declare(strict_types=1);

namespace App\Agent\Web\RuleChat;

use App\Agent\Application\CurrentAgent;
use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Application\RuleChatKnowledgeBaseResolver;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\ConversationNotFound;
use App\Chat\Domain\Message;
use App\Chat\Domain\ChatAnswerScoreRepositoryInterface;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Web\ChatThreadParams;
use App\Chat\Web\MessageScoreView;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Markdown\MarkdownRenderer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/** Older messages for the authenticated agent's Rule Chat thread. */
final readonly class HistoryAction
{
    public function __construct(
        private RuleChatKnowledgeBaseResolver $resolver,
        private FindOrCreateThreadService $threads,
        private MessageRepositoryInterface $messages,
        private ChatAnswerScoreRepositoryInterface $scores,
        private CurrentAgent $currentAgent,
        private MarkdownRenderer $markdown,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $knowledgeBase = $this->resolver->find();
        if ($knowledgeBase === null) {
            throw ConversationNotFound::inKnowledgeBase(0, 0);
        }

        $participant = ChatParticipant::agent($this->currentAgent->get()->adminId);
        $conversation = $this->threads->find($knowledgeBase->id(), $participant);
        if ($conversation === null) {
            throw ConversationNotFound::inKnowledgeBase(0, $knowledgeBase->id());
        }

        $before = (int) ($request->getQueryParams()['before_message_id'] ?? 0);
        if ($before < 1) {
            throw new NotFoundException('message_not_found', 'Message not found.');
        }

        $older = $this->messages->findBefore(
            $conversation->id,
            $before,
            ChatThreadParams::RECENT_MESSAGE_LIMIT,
        );
        if ($older === null) {
            throw new NotFoundException('message_not_found', 'Message not found in this conversation.');
        }

        $hasOlder = false;
        if ($older !== []) {
            $more = $this->messages->findBefore($conversation->id, $older[0]->id, 1);
            $hasOlder = $more !== null && $more !== [];
        }

        // One lookup for the whole page — the same read model the server-rendered thread uses.
        $scoreStates = MessageScoreView::compute($this->scores, $older, $participant);

        $payload = [
            'has_older' => $hasOlder,
            'messages' => array_map(
                fn(Message $m): array => [
                    'id' => $m->id,
                    'role' => $m->role->value,
                    'content' => $m->content,
                    'html' => $m->isAssistant() ? $this->markdown->toHtml($m->content) : null,
                    'is_grounded' => $m->isGrounded,
                    'citations' => array_map(
                        static fn($c): array => ['filename' => $c->filename],
                        $m->citations,
                    ),
                    'created_at' => $m->createdAt->format('Y-m-d H:i'),
                    // Read-only on this path: an older answer shows a score it already has, not the control.
                    'score' => $scoreStates->stateFor($m->id)?->isRated() === true
                        ? $scoreStates->stateFor($m->id)?->score
                        : null,
                ],
                $older,
            ),
        ];

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}
