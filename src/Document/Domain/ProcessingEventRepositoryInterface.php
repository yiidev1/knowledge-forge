<?php

declare(strict_types=1);

namespace App\Document\Domain;

/**
 * Append-only log of what happened to a document, satisfying the "view processing errors" requirement.
 *
 * Messages must be redacted and length-capped by the caller before they arrive here — this boundary
 * does not itself sanitise. Metadata is a small JSON-serialisable map of safe context.
 */
interface ProcessingEventRepositoryInterface
{
    /**
     * @param array<string, scalar|null> $metadata Safe, non-secret context (e.g. size, mime, kind).
     */
    public function record(int $documentId, string $status, ?string $message = null, array $metadata = []): void;

    /**
     * @return list<ProcessingEvent> Events for the document, most recent last.
     */
    public function findAllForDocument(int $documentId): array;
}
