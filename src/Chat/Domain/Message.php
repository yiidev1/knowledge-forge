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
    ) {}

    public function isAssistant(): bool
    {
        return $this->role === MessageRole::Assistant;
    }
}
