<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use DateTimeImmutable;

/**
 * One message in a conversation.
 *
 * A user message is just text. An assistant message additionally carries its resolved citations and the
 * grounding verdict ({@see $isGrounded}, {@see $retrievalStatus}) recorded at answer time, so the thread
 * can be re-rendered later without recomputing anything or re-querying the provider.
 *
 * The editing feature adds four fields. On an assistant message, {@see $replyToMessageId} points at the
 * user question it answers and {@see $supersededAt} marks an answer that a later edit replaced — hidden
 * from the live thread and from OpenAI history, but kept for audit. On a user message, {@see $editedAt}
 * and {@see $editCount} record that the question was edited; {@see $editCount} doubles as the optimistic
 * lock version.
 */
final readonly class Message
{
    /**
     * @param list<ResolvedCitation> $citations
     */
    public function __construct(
        public int $id,
        public int $conversationId,
        public MessageRole $role,
        public string $content,
        public array $citations,
        public bool $isGrounded,
        public ?string $retrievalStatus,
        public ?string $model,
        public DateTimeImmutable $createdAt,
        public ?int $replyToMessageId = null,
        public ?DateTimeImmutable $supersededAt = null,
        public ?DateTimeImmutable $editedAt = null,
        public int $editCount = 0,
    ) {}

    public function isAssistant(): bool
    {
        return $this->role === MessageRole::Assistant;
    }

    public function isUser(): bool
    {
        return $this->role === MessageRole::User;
    }

    public function isEdited(): bool
    {
        return $this->editCount > 0;
    }

    public function isSuperseded(): bool
    {
        return $this->supersededAt !== null;
    }
}
