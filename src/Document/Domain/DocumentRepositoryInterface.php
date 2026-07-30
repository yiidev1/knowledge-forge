<?php

declare(strict_types=1);

namespace App\Document\Domain;

/**
 * Persistence boundary for documents. Returns typed entities; every read and write is scoped by
 * knowledge base where a caller could otherwise reach across bases.
 */
interface DocumentRepositoryInterface
{
    public function findByIdForKnowledgeBase(int $documentId, int $knowledgeBaseId): ?Document;

    /**
     * @return list<Document> Non-deleted documents for the knowledge base, newest first.
     */
    public function findAllForKnowledgeBase(int $knowledgeBaseId): array;

    /**
     * Count of live (non-deleted) documents, for enforcing the per-knowledge-base limit.
     */
    public function countLiveForKnowledgeBase(int $knowledgeBaseId): int;

    /**
     * Count of documents that finished indexing, so chat can refuse to run against a base with nothing
     * searchable yet.
     */
    public function countReadyForKnowledgeBase(int $knowledgeBaseId): int;

    /**
     * Whether a live document with this checksum already exists in the knowledge base. A fast
     * pre-check; the database's unique index is the actual guarantee against a race.
     */
    public function liveChecksumExists(string $checksum, int $knowledgeBaseId): bool;

    /**
     * Inserts a queued document.
     *
     * @return int The id of the created document.
     */
    public function createQueued(NewDocument $document): int;

    /**
     * Marks a document deleted (soft delete) and records when. The stored file is removed separately by
     * the caller; the row is kept for audit and to free the dedupe slot.
     */
    public function markDeleted(int $documentId): void;

    /**
     * Returns a document to a clean `queued` state so the worker re-runs the full pipeline: attempts,
     * backoff, error and the processing-start marker are all cleared. Used by retry and re-index, whose
     * old index files are flagged for removal separately.
     */
    public function requeueFresh(int $documentId): void;

    /**
     * Raises a document's priority and clears its backoff so the next worker run picks it up ahead of
     * the queue. "Process now" — enqueue-only, no remote work in the request.
     */
    public function bumpPriority(int $documentId): void;
}
