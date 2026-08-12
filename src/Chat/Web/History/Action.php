<?php

declare(strict_types=1);

namespace App\Chat\Web\History;

use App\Auth\Application\CurrentAdmin;
use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\ConversationNotFound;
use App\Chat\Domain\Message;
use App\Chat\Domain\ChatAnswerScoreRepositoryInterface;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Web\ChatThreadParams;
use App\Chat\Web\MessageScoreView;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Markdown\MarkdownRenderer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Older messages for the logged-in admin's thread.
 */
final readonly class Action
{
    public function __construct(
        private KnowledgeBaseFinder $finder,
        private FindOrCreateThreadService $threads,
        private MessageRepositoryInterface $messages,
        private ChatAnswerScoreRepositoryInterface $scores,
        private MarkdownRenderer $markdown,
        private ResponseFactoryInterface $responseFactory,
        private CurrentAdmin $currentAdmin,
    ) {}

    public function __invoke(
        #[RouteArgument]
        string $slug,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $knowledgeBase = $this->finder->bySlug($slug);
        $participant = ChatParticipant::admin($this->currentAdmin->get()->id());
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
            $oldestId = $older[0]->id;
            $more = $this->messages->findBefore($conversation->id, $oldestId, 1);
            $hasOlder = $more !== null && $more !== [];
        }

        // One lookup for the whole page — the same read model the server-rendered thread uses.
        $scoreStates = MessageScoreView::compute($this->scores, $older, $participant);

        $payload = [
            'has_older' => $hasOlder,
            'messages' => array_map(
                fn(Message $m): array => $this->serialize($m, $scoreStates),
                $older,
            ),
        ];

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Message $message, MessageScoreView $scores): array
    {
        $state = $scores->stateFor($message->id);

        $citations = [];
        foreach ($message->citations as $citation) {
            $citations[] = ['filename' => $citation->filename];
        }

        return [
            'id' => $message->id,
            'role' => $message->role->value,
            'content' => $message->content,
            'html' => $message->isAssistant() ? $this->markdown->toHtml($message->content) : null,
            'is_grounded' => $message->isGrounded,
            'citations' => $citations,
            'created_at' => $message->createdAt->format('Y-m-d H:i'),
            // Read-only on this path: an older answer shows a score it already has, but not the control.
            'score' => $state !== null && $state->isRated() ? $state->score : null,
        ];
    }
}
