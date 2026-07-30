<?php

declare(strict_types=1);

namespace App\Document\Domain;

use DateTimeImmutable;

/**
 * Worker-facing persistence for the document processing lifecycle.
 *
 * Separate from {@see DocumentRepositoryInterface} (which serves the web CRUD): these methods are
 * cross-knowledge-base by design, since the worker drains all documents, and the claim is the atomic
 * conditional update that lets many workers run without processing the same document twice.
 */
interface DocumentProcessingRepositoryInterface
{
    /**
     * @return list<Document> Documents eligible for a processing run — queued or indexing, whose backoff
     *                        has elapsed — ordered by priority (desc) then oldest-scheduled first.
     */
    public function findProcessable(int $limit, DateTimeImmutable $now): array;

    public function find(int $documentId): ?Document;

    /**
     * Atomically transitions a document from an expected status to `processing`, stamping the claim time.
     * A queued→processing claim also increments the attempt count; an indexing→processing re-claim (a
     * poll) does not, since polling is not a fresh attempt.
     *
     * @return bool True if this worker won the claim.
     */
    public function claim(int $documentId, DocumentStatus $expected, DateTimeImmutable $now): bool;

    public function markReady(int $documentId, DateTimeImmutable $now): void;

    public function markIndexing(int $documentId, DateTimeImmutable $nextAttemptAt): void;

    /**
     * Returns a document to `queued` with a future retry time and a safe error message.
     */
    public function requeue(int $documentId, DateTimeImmutable $nextAttemptAt, ?string $errorCode, ?string $errorMessage): void;

    public function markFailed(int $documentId, ?string $errorCode, ?string $errorMessage): void;

    /**
     * Returns documents stuck in `processing` since before $threshold to `queued`.
     *
     * @return int Number recovered.
     */
    public function recoverStuck(DateTimeImmutable $threshold, DateTimeImmutable $now): int;
}
