<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

/**
 * Chat cannot run for this knowledge base yet: its vector store is not provisioned, or it has no usable
 * qualifying document (the Order58 store profile alone never qualifies). Answering from an unprovisioned
 * store or a base with no enabled, indexed knowledge document would only ever produce the fallback, so the
 * UI blocks the question and the server rejects it. Messages are user-safe and identical for admin and
 * agent — they never expose internal sync/vector-store detail.
 */
final class ChatUnavailable extends DomainException
{
    public function errorCode(): string
    {
        return 'chat_unavailable';
    }

    public static function sourceInactive(): self
    {
        return new self('This store is inactive in Order58, so chat is unavailable until the source store becomes active again.');
    }

    public static function notProvisioned(): self
    {
        return new self('This knowledge base is still being provisioned. Chat becomes available once it is ready.');
    }

    public static function noReadyDocuments(): self
    {
        return new self('Chat is unavailable until at least one enabled Knowledge Base document has finished processing and is ready.');
    }

    /**
     * An Order58-linked base is missing a usable store-profile snapshot and/or a usable qualifying document.
     */
    public static function order58NotReady(): self
    {
        return new self('Chat is unavailable until the store profile and at least one Knowledge Base document are ready.');
    }
}
