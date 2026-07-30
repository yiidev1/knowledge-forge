<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

/**
 * Chat cannot run for this knowledge base yet: its vector store is not provisioned, or it has no
 * document that finished indexing. Answering from an empty or unprovisioned store would only ever
 * produce the fallback, so the UI blocks the question instead.
 */
final class ChatUnavailable extends DomainException
{
    public function errorCode(): string
    {
        return 'chat_unavailable';
    }

    public static function notProvisioned(): self
    {
        return new self('This knowledge base is still being provisioned. Chat becomes available once it is ready.');
    }

    public static function noReadyDocuments(): self
    {
        return new self('This knowledge base has no indexed documents yet. Upload and index a document before asking a question.');
    }
}
