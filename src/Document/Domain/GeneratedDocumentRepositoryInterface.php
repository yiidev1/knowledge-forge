<?php

declare(strict_types=1);

namespace App\Document\Domain;

use DateTimeImmutable;

/**
 * Persistence for source-derived ("generated") documents — the Order58 store-profile and knowledge
 * documents — keyed by (knowledge base, source type, source ref) so each source record maps to exactly one
 * document that updates in place rather than duplicating.
 *
 * These rows live in the same `documents` table and flow through the same worker indexing pipeline as
 * uploads; this interface is kept separate from {@see DocumentRepositoryInterface} so the upload side and
 * its tests are untouched.
 */
interface GeneratedDocumentRepositoryInterface
{
    public function findBySource(int $knowledgeBaseId, string $sourceType, string $sourceRef): ?GeneratedDocument;

    /**
     * Every live (enabled, not-deleted) generated document of this source type across all knowledge bases, as
     * (knowledge base, source ref) locations. Backs a fleet-wide retire/report of a projection type whose owning
     * rule link may no longer exist. One bounded query — no per-row lookup.
     *
     * @return list<GeneratedDocumentLocation>
     */
    public function findLiveLocationsByType(string $sourceType): array;

    /**
     * Inserts a new generated document as `queued` (kind = text), ready for the existing processing drainer.
     *
     * @return int The new document id.
     */
    public function create(
        int $knowledgeBaseId,
        string $sourceType,
        string $sourceRef,
        string $title,
        string $syncHash,
        string $storedPath,
        string $storageToken,
        string $checksum,
        int $sizeBytes,
        DateTimeImmutable $now,
    ): int;

    /**
     * Updates a changed (or previously-deactivated) generated document and requeues it fresh for
     * re-indexing. The caller flags the old index files for removal first, so the old vector-store copy
     * stays until the replacement indexes.
     */
    public function reindex(
        int $id,
        string $title,
        string $syncHash,
        string $checksum,
        int $sizeBytes,
        DateTimeImmutable $now,
    ): void;

    /**
     * Soft-disables a generated document whose source disappeared: hidden from retrieval, its index files
     * flagged for removal by the caller, audit history preserved.
     */
    public function disable(int $id, DateTimeImmutable $now): void;
}
