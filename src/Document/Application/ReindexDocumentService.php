<?php

declare(strict_types=1);

namespace App\Document\Application;

use App\Document\Domain\Document;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\Exception\DocumentActionNotAllowed;
use App\Document\Domain\Exception\DocumentNotFound;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Document\Domain\ProcessingEventRepositoryInterface;

/**
 * Re-indexes a document that is already `ready`. Enqueue-only: the current vector-store files are
 * flagged for background removal and the row is reset to a clean `queued` state, so the worker rebuilds
 * the index from scratch. Persisted derived Markdown is reused, so a re-index does not re-run vision.
 */
final readonly class ReindexDocumentService
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private IndexedFileRepositoryInterface $indexedFiles,
        private ProcessingEventRepositoryInterface $events,
    ) {}

    public function reindex(int $knowledgeBaseId, int $documentId): void
    {
        $document = $this->documents->findByIdForKnowledgeBase($documentId, $knowledgeBaseId);

        if (!$document instanceof Document) {
            throw DocumentNotFound::inKnowledgeBase($documentId, $knowledgeBaseId);
        }

        if ($document->status() !== DocumentStatus::Ready) {
            throw DocumentActionNotAllowed::forStatus('re-indexed', $document->status());
        }

        $this->indexedFiles->markPendingRemovalByDocument($document->id());
        $this->documents->requeueFresh($document->id());
        $this->events->record($document->id(), 'queued', 'Re-index requested by administrator.');
    }
}
