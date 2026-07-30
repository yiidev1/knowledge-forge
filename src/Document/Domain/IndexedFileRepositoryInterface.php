<?php

declare(strict_types=1);

namespace App\Document\Domain;

use App\Ai\Contract\Dto\IndexStatus;

/**
 * Persistence boundary for the OpenAI artifacts derived from documents.
 *
 * A row is created `pending` before the upload so the reliable-operation key can reference it; the file
 * id and status are filled in once the upload and attach succeed, and updated as indexing progresses.
 */
interface IndexedFileRepositoryInterface
{
    /**
     * Creates a pending indexed-file row (no provider id yet) and returns its id.
     */
    public function createPending(int $documentId, IndexedFileRole $role, ?string $derivedPath): int;

    public function setUploaded(int $indexedFileId, string $openaiFileId, IndexStatus $status): void;

    public function updateState(
        int $indexedFileId,
        IndexStatus $status,
        ?int $usageBytes,
        ?string $errorCode,
        ?string $errorMessage,
    ): void;

    /**
     * Clears the provider id after the remote file has been deleted, so cleanup is idempotent.
     */
    public function clearOpenaiFileId(int $indexedFileId): void;

    public function findById(int $indexedFileId): ?IndexedFile;

    /**
     * Active index files for a document — rows flagged for removal are excluded, so a re-index's old
     * files never confuse the fresh run that produced them.
     *
     * @return list<IndexedFile>
     */
    public function findByDocument(int $documentId): array;

    public function findByOpenaiFileId(string $openaiFileId): ?IndexedFile;

    public function deleteByDocument(int $documentId): void;

    /**
     * Flags every index file of a document for background remote removal. Used by delete and re-index:
     * the web request returns immediately and the cleanup drainer detaches and deletes the remote objects.
     */
    public function markPendingRemovalByDocument(int $documentId): void;

    /**
     * Index files awaiting remote removal, oldest first, capped at $limit.
     *
     * @return list<IndexedFile>
     */
    public function findPendingRemoval(int $limit): array;

    /**
     * Hard-deletes a single index-file row once its remote object is gone.
     */
    public function delete(int $indexedFileId): void;
}
