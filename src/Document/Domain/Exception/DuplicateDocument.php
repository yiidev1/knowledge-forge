<?php

declare(strict_types=1);

namespace App\Document\Domain\Exception;

/**
 * A live document with the same content (SHA-256) already exists in this knowledge base.
 *
 * Detected up front by checksum, and also enforced by a unique index so a race cannot slip a duplicate
 * through. The message intentionally does not name the existing document, to avoid leaking one
 * uploader's filenames to another in a future multi-user world.
 */
final class DuplicateDocument extends UploadException
{
    public function errorCode(): string
    {
        return 'duplicate_document';
    }

    public static function inKnowledgeBase(): self
    {
        return new self('This exact file has already been uploaded to this knowledge base.');
    }
}
