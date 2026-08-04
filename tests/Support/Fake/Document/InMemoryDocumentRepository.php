<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Document;

use App\Document\Domain\CanonicalDocument;
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
 * In-memory document repository for unit tests.
 */
final class InMemoryDocumentRepository implements DocumentRepositoryInterface
{
    /** @var array<int, Document> */
    private array $items = [];

    /** @var array<int, CanonicalDocument> */
    private array $canonical = [];

    /** @var array<int, bool> */
    private array $prioritised = [];

    /** @var list<int> */
    public array $requeued = [];

    /** @var list<int> */
    public array $overridden = [];

    /** @var list<int> */
    public array $clearedOverrides = [];

    /** @var array<int, bool> Per-KB "has a usable Order58 store-profile snapshot" (test-controlled). */
    private array $usableProfile = [];

    /** @var array<int, bool> Per-KB "has a usable qualifying (non-profile) document" (test-controlled). */
    private array $usableQualifying = [];

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

    public function findCanonicalForKnowledgeBase(int $documentId, int $knowledgeBaseId): ?CanonicalDocument
    {
        $doc = $this->canonical[$documentId] ?? null;
        if ($doc === null || $doc->knowledgeBaseId !== $knowledgeBaseId || $doc->status === DocumentStatus::Deleted) {
            return null;
        }

        return $doc;
    }

    public function seedCanonical(CanonicalDocument $document): void
    {
        $this->canonical[$document->id] = $document;
        if ($document->id >= $this->nextId) {
            $this->nextId = $document->id + 1;
        }
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

    public function hasUsableOrder58StoreProfile(int $knowledgeBaseId): bool
    {
        return $this->usableProfile[$knowledgeBaseId] ?? false;
    }

    public function hasUsableQualifyingDocument(int $knowledgeBaseId): bool
    {
        return $this->usableQualifying[$knowledgeBaseId] ?? false;
    }

    public function setUsableStoreProfile(int $knowledgeBaseId, bool $usable): void
    {
        $this->usableProfile[$knowledgeBaseId] = $usable;
    }

    public function setUsableQualifyingDocument(int $knowledgeBaseId, bool $usable): void
    {
        $this->usableQualifying[$knowledgeBaseId] = $usable;
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

    public function liveChecksumExists(string $checksum, int $knowledgeBaseId, ?int $exceptDocumentId = null): bool
    {
        foreach ($this->items as $doc) {
            if ($exceptDocumentId !== null && $doc->id() === $exceptDocumentId) {
                continue;
            }
            if ($doc->knowledgeBaseId() === $knowledgeBaseId
                && $doc->checksumSha256() === $checksum
                && $doc->status() !== DocumentStatus::Deleted) {
                return true;
            }
        }
        foreach ($this->canonical as $doc) {
            if ($exceptDocumentId !== null && $doc->id === $exceptDocumentId) {
                continue;
            }
            if ($doc->knowledgeBaseId === $knowledgeBaseId
                && $doc->checksumSha256 === $checksum
                && $doc->status !== DocumentStatus::Deleted) {
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

        $this->canonical[$id] = new CanonicalDocument(
            id: $id,
            knowledgeBaseId: $document->knowledgeBaseId,
            sourceType: $document->sourceType,
            kind: $document->kind,
            status: DocumentStatus::Queued,
            title: $document->title ?? $document->originalFilename,
            originalFilename: $document->originalFilename,
            storedPath: $document->storedPath,
            storageToken: $document->storageToken,
            mimeType: $document->mimeType,
            extension: $document->extension,
            sizeBytes: $document->sizeBytes,
            checksumSha256: $document->checksumSha256,
            sourceText: $document->sourceText,
            sourceRef: null,
            isSourceOverridden: false,
        );

        return $id;
    }

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
            checksumSha256: str_repeat((string) ($id % 10), 64),
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
        $this->requeued[] = $documentId;
    }

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

    public function updateTitle(int $documentId, string $title, DateTimeImmutable $now): void
    {
        if (isset($this->canonical[$documentId])) {
            $c = $this->canonical[$documentId];
            $this->canonical[$documentId] = new CanonicalDocument(
                id: $c->id,
                knowledgeBaseId: $c->knowledgeBaseId,
                sourceType: $c->sourceType,
                kind: $c->kind,
                status: $c->status,
                title: $title,
                originalFilename: $c->originalFilename,
                storedPath: $c->storedPath,
                storageToken: $c->storageToken,
                mimeType: $c->mimeType,
                extension: $c->extension,
                sizeBytes: $c->sizeBytes,
                checksumSha256: $c->checksumSha256,
                sourceText: $c->sourceText,
                sourceRef: $c->sourceRef,
                isSourceOverridden: $c->isSourceOverridden,
            );
        }
    }

    public function replaceBinarySource(
        int $documentId,
        string $title,
        string $originalFilename,
        string $mimeType,
        string $extension,
        int $sizeBytes,
        string $checksum,
        DateTimeImmutable $now,
    ): void {
        $this->requeueFresh($documentId);
        if (isset($this->canonical[$documentId])) {
            $c = $this->canonical[$documentId];
            $this->canonical[$documentId] = new CanonicalDocument(
                id: $c->id,
                knowledgeBaseId: $c->knowledgeBaseId,
                sourceType: $c->sourceType,
                kind: $c->kind,
                status: DocumentStatus::Queued,
                title: $title,
                originalFilename: $originalFilename,
                storedPath: $c->storedPath,
                storageToken: $c->storageToken,
                mimeType: $mimeType,
                extension: $extension,
                sizeBytes: $sizeBytes,
                checksumSha256: $checksum,
                sourceText: $c->sourceText,
                sourceRef: $c->sourceRef,
                isSourceOverridden: $c->isSourceOverridden,
            );
        }
    }

    public function applySourceOverride(
        int $documentId,
        string $title,
        string $checksum,
        int $sizeBytes,
        DateTimeImmutable $now,
        bool $requeue = true,
    ): void {
        $this->overridden[] = $documentId;
        if ($requeue) {
            $this->requeueFresh($documentId);
        }
        if (isset($this->canonical[$documentId])) {
            $c = $this->canonical[$documentId];
            $this->canonical[$documentId] = new CanonicalDocument(
                id: $c->id,
                knowledgeBaseId: $c->knowledgeBaseId,
                sourceType: $c->sourceType,
                kind: $c->kind,
                status: $requeue ? DocumentStatus::Queued : $c->status,
                title: $title,
                originalFilename: $title,
                storedPath: $c->storedPath,
                storageToken: $c->storageToken,
                mimeType: $c->mimeType,
                extension: $c->extension,
                sizeBytes: $sizeBytes,
                checksumSha256: $checksum,
                sourceText: $c->sourceText,
                sourceRef: $c->sourceRef,
                isSourceOverridden: true,
            );
        }
    }

    public function clearSourceOverride(
        int $documentId,
        string $title,
        string $syncHash,
        string $checksum,
        int $sizeBytes,
        DateTimeImmutable $now,
    ): void {
        $this->clearedOverrides[] = $documentId;
        $this->requeueFresh($documentId);
        if (isset($this->canonical[$documentId])) {
            $c = $this->canonical[$documentId];
            $this->canonical[$documentId] = new CanonicalDocument(
                id: $c->id,
                knowledgeBaseId: $c->knowledgeBaseId,
                sourceType: $c->sourceType,
                kind: $c->kind,
                status: DocumentStatus::Queued,
                title: $title,
                originalFilename: $title,
                storedPath: $c->storedPath,
                storageToken: $c->storageToken,
                mimeType: $c->mimeType,
                extension: $c->extension,
                sizeBytes: $sizeBytes,
                checksumSha256: $checksum,
                sourceText: $c->sourceText,
                sourceRef: $c->sourceRef,
                isSourceOverridden: false,
            );
        }
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
