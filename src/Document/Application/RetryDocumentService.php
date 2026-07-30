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
 * Retries a failed document. Enqueue-only: it resets the row to a clean `queued` state and flags any
 * remote files from the failed attempt for background removal, then returns. The worker re-runs the
 * full pipeline (reusing persisted derived Markdown, so a vision extraction is not repeated).
 */
final readonly class RetryDocumentService
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private IndexedFileRepositoryInterface $indexedFiles,
        private ProcessingEventRepositoryInterface $events,
    ) {}

    public function retry(int $knowledgeBaseId, int $documentId): void
    {
        $document = $this->documents->findByIdForKnowledgeBase($documentId, $knowledgeBaseId);

        if (!$document instanceof Document) {
            throw DocumentNotFound::inKnowledgeBase($documentId, $knowledgeBaseId);
        }

        if ($document->status() !== DocumentStatus::Failed) {
            throw DocumentActionNotAllowed::forStatus('retried', $document->status());
        }

        $this->indexedFiles->markPendingRemovalByDocument($document->id());
        $this->documents->requeueFresh($document->id());
        $this->events->record($document->id(), 'queued', 'Retry requested by administrator.');
    }
}
