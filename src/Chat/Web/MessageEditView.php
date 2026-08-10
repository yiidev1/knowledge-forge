<?php

declare(strict_types=1);

namespace App\Chat\Web;

use App\Chat\Domain\MessageRepositoryInterface;

/**
 * Server-computed edit affordances for a rendered thread, shared by the admin and agent chat pages.
 *
 * Editability is decided here, not in the template or the browser: only the latest user question is editable
 * ({@see $editableMessageId}), with no age limit. A latest question with no active answer (regeneration failed
 * or in flight) is offered a retry instead ({@see $retryMessageId}), which also drives disabling the composer.
 * Both are ids, so the template only has to compare.
 */
final readonly class MessageEditView
{
    private function __construct(
        public ?int $editableMessageId,
        public ?int $retryMessageId,
    ) {}

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function compute(
        MessageRepositoryInterface $messages,
        int $conversationId,
        bool $chatReady,
    ): self {
        if (!$chatReady) {
            return self::none();
        }

        $latestUserId = $messages->findLatestUserMessageId($conversationId);
        if ($latestUserId === null) {
            return self::none();
        }

        $retryMessageId = $messages->hasUnansweredLatestUserMessage($conversationId) ? $latestUserId : null;

        return new self($latestUserId, $retryMessageId);
    }

    public function isEditable(int $messageId): bool
    {
        return $this->editableMessageId === $messageId;
    }

    public function isRetry(int $messageId): bool
    {
        return $this->retryMessageId === $messageId;
    }

    /**
     * Whether the composer should be blocked: the latest question has no active answer and must be resolved
     * (retry or edit) before a new question is allowed — mirroring the server-side guard.
     */
    public function hasBlockedComposer(): bool
    {
        return $this->retryMessageId !== null;
    }
}
