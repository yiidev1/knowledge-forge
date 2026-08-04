<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Document;

use App\Ai\Contract\Dto\IndexStatus;
use App\Document\Domain\IndexedFile;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Document\Domain\IndexedFileRole;
use DateTimeImmutable;
use DateTimeZone;

use function array_values;

/**
 * In-memory index-file repository. Mirrors the real one's contract, including that {@see findByDocument}
 * excludes rows flagged for removal, so processing never sees a re-index's old files.
 */
final class InMemoryIndexedFileRepository implements IndexedFileRepositoryInterface
{
    /** @var array<int, IndexedFile> */
    private array $items = [];

    /** @var array<int, bool> */
    private array $pendingRemoval = [];

    /** @var array<int, bool> document ids that are deleted/disabled (their flagged files become removable). */
    private array $removableDocuments = [];

    private int $nextId = 1;

    /**
     * Marks a document deleted/disabled for the cleanup guard, mirroring the DB check
     * (documents.status='deleted' OR is_enabled=0). Its flagged files then become eligible for cleanup.
     */
    public function markDocumentRemovable(int $documentId): void
    {
        $this->removableDocuments[$documentId] = true;
    }

    public function createPending(int $documentId, IndexedFileRole $role, ?string $derivedPath): int
    {
        $id = $this->nextId++;
        $this->items[$id] = new IndexedFile(
            id: $id,
            documentId: $documentId,
            role: $role,
            derivedPath: $derivedPath,
            openaiFileId: null,
            indexStatus: IndexStatus::Pending,
            usageBytes: null,
            lastErrorCode: null,
            lastErrorMessage: null,
            createdAt: $this->now(),
            updatedAt: $this->now(),
        );
        $this->pendingRemoval[$id] = false;

        return $id;
    }

    public function setUploaded(int $indexedFileId, string $openaiFileId, IndexStatus $status): void
    {
        $this->mutate($indexedFileId, openaiFileId: $openaiFileId, status: $status);
    }

    public function updateState(int $indexedFileId, IndexStatus $status, ?int $usageBytes, ?string $errorCode, ?string $errorMessage): void
    {
        $this->mutate($indexedFileId, status: $status, usageBytes: $usageBytes, errorCode: $errorCode, errorMessage: $errorMessage);
    }

    public function clearOpenaiFileId(int $indexedFileId): void
    {
        $this->mutate($indexedFileId, clearFileId: true);
    }

    public function findById(int $indexedFileId): ?IndexedFile
    {
        return $this->items[$indexedFileId] ?? null;
    }

    public function findByDocument(int $documentId): array
    {
        $result = [];
        foreach ($this->items as $file) {
            if ($file->documentId === $documentId && !($this->pendingRemoval[$file->id] ?? false)) {
                $result[] = $file;
            }
        }

        return array_values($result);
    }

    public function findByOpenaiFileId(string $openaiFileId): ?IndexedFile
    {
        foreach ($this->items as $file) {
            if ($file->openaiFileId === $openaiFileId) {
                return $file;
            }
        }

        return null;
    }

    public function deleteByDocument(int $documentId): void
    {
        foreach ($this->items as $id => $file) {
            if ($file->documentId === $documentId) {
                unset($this->items[$id], $this->pendingRemoval[$id]);
            }
        }
    }

    public function markPendingRemovalByDocument(int $documentId): void
    {
        foreach ($this->items as $file) {
            if ($file->documentId === $documentId) {
                $this->pendingRemoval[$file->id] = true;
            }
        }
    }

    /**
     * @return list<int> Document ids that have at least one file flagged for removal.
     */
    public function pendingRemovalDocumentIds(): array
    {
        $ids = [];
        foreach ($this->items as $file) {
            if ($this->pendingRemoval[$file->id] ?? false) {
                $ids[$file->documentId] = true;
            }
        }

        return array_keys($ids);
    }

    public function seed(int $documentId, IndexedFileRole $role, string $openaiFileId): int
    {
        $id = $this->createPending($documentId, $role, null);
        $this->setUploaded($id, $openaiFileId, IndexStatus::Completed);

        return $id;
    }

    public function findPendingRemoval(int $limit): array
    {
        $result = [];
        foreach ($this->items as $file) {
            if (!($this->pendingRemoval[$file->id] ?? false)) {
                continue;
            }
            // Replacement guard (mirrors the DB): a flagged file is removable only when its document is
            // deleted/disabled, OR a completed non-flagged replacement definitely exists. "No incomplete
            // replacement" is NOT sufficient — a resync whose new file has not been created yet keeps the old.
            if (!$this->isRemovable($file->documentId)) {
                continue;
            }
            $result[] = $file;
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    private function isRemovable(int $documentId): bool
    {
        if ($this->removableDocuments[$documentId] ?? false) {
            return true;
        }

        foreach ($this->items as $file) {
            if (
                $file->documentId === $documentId
                && !($this->pendingRemoval[$file->id] ?? false)
                && $file->indexStatus === IndexStatus::Completed
                && $file->openaiFileId !== null
            ) {
                return true;
            }
        }

        return false;
    }

    public function delete(int $indexedFileId): void
    {
        unset($this->items[$indexedFileId], $this->pendingRemoval[$indexedFileId]);
    }

    /** @return list<IndexedFile> Every row, flagged or not — for assertions. */
    public function all(): array
    {
        return array_values($this->items);
    }

    private function mutate(
        int $id,
        ?string $openaiFileId = null,
        ?IndexStatus $status = null,
        ?int $usageBytes = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        bool $clearFileId = false,
    ): void {
        $file = $this->items[$id] ?? null;
        if ($file === null) {
            return;
        }

        $this->items[$id] = new IndexedFile(
            id: $file->id,
            documentId: $file->documentId,
            role: $file->role,
            derivedPath: $file->derivedPath,
            openaiFileId: $clearFileId ? null : ($openaiFileId ?? $file->openaiFileId),
            indexStatus: $status ?? $file->indexStatus,
            usageBytes: $usageBytes ?? $file->usageBytes,
            lastErrorCode: $errorCode ?? $file->lastErrorCode,
            lastErrorMessage: $errorMessage ?? $file->lastErrorMessage,
            createdAt: $file->createdAt,
            updatedAt: $this->now(),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    }
}
