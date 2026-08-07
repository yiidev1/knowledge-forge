<?php

declare(strict_types=1);

namespace App\Document\Domain;

use DateTimeImmutable;

/**
 * Persistence boundary for documents. Returns typed entities; every read and write is scoped by
 * knowledge base where a caller could otherwise reach across bases.
 */
interface DocumentRepositoryInterface
{
    public function findByIdForKnowledgeBase(int $documentId, int $knowledgeBaseId): ?Document;

    /**
     * Canonical source row for View / Download / Edit (includes source_type and override flag).
     */
    public function findCanonicalForKnowledgeBase(int $documentId, int $knowledgeBaseId): ?CanonicalDocument;

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
     * searchable yet. Counts every source type (including the Order58 store profile) and is used for
     * vector-store inventory/usage reporting — NOT for chat availability, which uses the usable-snapshot
     * checks below.
     */
    public function countReadyForKnowledgeBase(int $knowledgeBaseId): int;

    /**
     * Whether this knowledge base has a usable Order58 store-profile snapshot: an `order58_store_profile`
     * document, enabled and not deleted, that currently has a completed vector-store file. Resilient to a
     * resync in progress or a failed refresh — the previous completed file keeps it usable until a
     * replacement is fully indexed. Scoped strictly to $knowledgeBaseId.
     */
    public function hasUsableOrder58StoreProfile(int $knowledgeBaseId): bool;

    /**
     * Whether this knowledge base has at least one usable *qualifying* document: enabled, not deleted, with a
     * completed vector-store file, and whose source type is genuine answerable content. The store-profile
     * snapshot and the rule projections (order58_rule_store/global/common) never satisfy this requirement — a
     * base whose only ready content is rules and/or the profile is not chattable. The excluded set is
     * {@see \App\Document\Domain\DocumentSourceType::nonQualifyingChatContentValues()}. Scoped strictly to
     * $knowledgeBaseId.
     */
    public function hasUsableQualifyingDocument(int $knowledgeBaseId): bool;

    /**
     * Whether the (hidden Global Rules) knowledge base has at least one usable, completed, enabled global rule
     * document (`order58_rule_global`, or a legacy `order58_rule_common` awaiting re-projection). The dedicated
     * readiness check for the stage-2 global-rules chat fallback — it does NOT go through
     * {@see \App\Chat\Application\ChatAvailabilityPolicy}, which governs store chat only.
     */
    public function hasUsableGlobalRuleDocument(int $knowledgeBaseId): bool;

    /**
     * The `source_type` of a document by id, or null if it does not exist. Used to label a chat answer's
     * source (store_rule vs store_knowledge) from its winning citation.
     */
    public function sourceTypeOfDocument(int $documentId): ?string;

    /**
     * Whether a live document with this checksum already exists in the knowledge base. A fast
     * pre-check; the database's unique index is the actual guarantee against a race.
     *
     * @param int|null $exceptDocumentId When replacing a file, exclude the document being updated.
     */
    public function liveChecksumExists(string $checksum, int $knowledgeBaseId, ?int $exceptDocumentId = null): bool;

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

    /**
     * Title-only update (no requeue). Used when the indexed bytes are unchanged.
     */
    public function updateTitle(int $documentId, string $title, DateTimeImmutable $now): void;

    /**
     * Replaces binary source metadata after a successful file swap and requeues fresh.
     */
    public function replaceBinarySource(
        int $documentId,
        string $title,
        string $originalFilename,
        string $mimeType,
        string $extension,
        int $sizeBytes,
        string $checksum,
        DateTimeImmutable $now,
    ): void;

    /**
     * Applies Order58 local override content and requeues. Sets is_source_overridden = 1.
     */
    public function applySourceOverride(
        int $documentId,
        string $title,
        string $checksum,
        int $sizeBytes,
        DateTimeImmutable $now,
        bool $requeue = true,
    ): void;

    /**
     * Restores Order58 upstream content, clears override, and requeues.
     */
    public function clearSourceOverride(
        int $documentId,
        string $title,
        string $syncHash,
        string $checksum,
        int $sizeBytes,
        DateTimeImmutable $now,
    ): void;
}
