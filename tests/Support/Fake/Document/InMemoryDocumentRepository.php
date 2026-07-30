<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Document;

use App\Document\Domain\Document;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\NewDocument;
use DateTimeImmutable;
use DateTimeZone;

use function array_reverse;
use function array_values;
use function str_repeat;

/**
 * In-memory document repository for unit tests. The live/deleted distinction and per-base scoping
 * mirror the real repository; the database's unique dedupe index is exercised in the integration test.
 */
final class InMemoryDocumentRepository implements DocumentRepositoryInterface
{
    /** @var array<int, Document> */
    private array $items = [];

    /** @var array<int, bool> */
    private array $prioritised = [];

    private int $nextId = 1;

    public function findByIdForKnowledgeBase(int $documentId, int $knowledgeBaseId): ?Document
    {
        $doc = $this->items[$documentId] ?? null;

        return $doc !== null
            && $doc->knowledgeBaseId() === $knowledgeBaseId
            && $doc->status() !== DocumentStatus::Deleted
                ? $doc
                : null;
    }

    public function findAllForKnowledgeBase(int $knowledgeBaseId): array
    {
        $result = [];
        foreach ($this->items as $doc) {
            if ($doc->knowledgeBaseId() === $knowledgeBaseId && $doc->status() !== DocumentStatus::Deleted) {
                $result[] = $doc;
            }
        }

        return array_values(array_reverse($result));
    }

    public function countLiveForKnowledgeBase(int $knowledgeBaseId): int
    {
        return count($this->findAllForKnowledgeBase($knowledgeBaseId));
    }

    public function countReadyForKnowledgeBase(int $knowledgeBaseId): int
    {
        $count = 0;
        foreach ($this->items as $doc) {
            if ($doc->knowledgeBaseId() === $knowledgeBaseId && $doc->status() === DocumentStatus::Ready) {
                $count++;
            }
        }

        return $count;
    }

    public function liveChecksumExists(string $checksum, int $knowledgeBaseId): bool
    {
        foreach ($this->items as $doc) {
            if ($doc->knowledgeBaseId() === $knowledgeBaseId
                && $doc->checksumSha256() === $checksum
                && $doc->status() !== DocumentStatus::Deleted) {
                return true;
            }
        }

        return false;
    }

    public function createQueued(NewDocument $document): int
    {
        $id = $this->nextId++;
        $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        $this->items[$id] = new Document(
            id: $id,
            knowledgeBaseId: $document->knowledgeBaseId,
            originalFilename: $document->originalFilename,
            storedPath: $document->storedPath,
            storageToken: $document->storageToken,
            mimeType: $document->mimeType,
            extension: $document->extension,
            sizeBytes: $document->sizeBytes,
            checksumSha256: $document->checksumSha256,
            kind: $document->kind,
            status: DocumentStatus::Queued,
            processingAttempts: 0,
            errorCode: null,
            errorMessage: null,
            processedAt: null,
            createdAt: $now,
            updatedAt: $now,
        );

        return $id;
    }

    /**
     * Test helper: insert a document already in a given status, so the retry/re-index/process-now guards
     * can be exercised across states the web upload path never produces directly.
     */
    public function seed(int $id, int $knowledgeBaseId, DocumentStatus $status): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $this->items[$id] = new Document(
            id: $id,
            knowledgeBaseId: $knowledgeBaseId,
            originalFilename: 'doc.pdf',
            storedPath: 'kb/doc.pdf',
            storageToken: 'tok' . $id,
            mimeType: 'application/pdf',
            extension: 'pdf',
            sizeBytes: 1024,
            checksumSha256: str_repeat((string) $id, 64),
            kind: DocumentKind::Pdf,
            status: $status,
            processingAttempts: 0,
            errorCode: null,
            errorMessage: null,
            processedAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
        if ($id >= $this->nextId) {
            $this->nextId = $id + 1;
        }
    }

    public function markDeleted(int $documentId): void
    {
        $this->replaceStatus($documentId, DocumentStatus::Deleted);
    }

    public function requeueFresh(int $documentId): void
    {
        $doc = $this->items[$documentId] ?? null;
        if ($doc === null) {
            return;
        }

        $this->items[$documentId] = $this->with($doc, DocumentStatus::Queued, 0, null, null);
    }

    /** Priority is not modelled on the entity; the flag exists so the interface is satisfied. */
    public function bumpPriority(int $documentId): void
    {
        $this->prioritised[$documentId] = true;
    }

    public function wasPrioritised(int $documentId): bool
    {
        return $this->prioritised[$documentId] ?? false;
    }

    public function status(int $documentId): ?DocumentStatus
    {
        return ($this->items[$documentId] ?? null)?->status();
    }

    private function replaceStatus(int $documentId, DocumentStatus $status): void
    {
        $doc = $this->items[$documentId] ?? null;
        if ($doc === null) {
            return;
        }

        $this->items[$documentId] = $this->with($doc, $status, $doc->processingAttempts(), $doc->errorCode(), $doc->errorMessage());
    }

    private function with(Document $doc, DocumentStatus $status, int $attempts, ?string $errorCode, ?string $errorMessage): Document
    {
        return new Document(
            id: $doc->id(),
            knowledgeBaseId: $doc->knowledgeBaseId(),
            originalFilename: $doc->originalFilename(),
            storedPath: $doc->storedPath(),
            storageToken: $doc->storageToken(),
            mimeType: $doc->mimeType(),
            extension: $doc->extension(),
            sizeBytes: $doc->sizeBytes(),
            checksumSha256: $doc->checksumSha256(),
            kind: $doc->kind(),
            status: $status,
            processingAttempts: $attempts,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            processedAt: $doc->processedAt(),
            createdAt: $doc->createdAt(),
            updatedAt: $doc->updatedAt(),
        );
    }
}
