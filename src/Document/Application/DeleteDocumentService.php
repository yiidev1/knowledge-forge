<?php

declare(strict_types=1);

namespace App\Document\Application;

use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Domain\Document;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\Exception\DocumentNotFound;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Document\Domain\ProcessingEventRepositoryInterface;

/**
 * Removes a document from a knowledge base.
 *
 * The row is soft-deleted (kept for audit, and so its dedupe slot is freed for a future re-upload) and
 * the stored original file is removed from disk. Detaching the files from the OpenAI vector store is a
 * remote operation, so it is only enqueued here — the index-file rows are flagged for removal and the
 * background cleanup drainer detaches and deletes them. This service does only the fast local part,
 * which is why it lives here and not behind a blocking HTTP action.
 */
final readonly class DeleteDocumentService
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private IndexedFileRepositoryInterface $indexedFiles,
        private ProcessingEventRepositoryInterface $events,
        private DocumentStorageInterface $storage,
    ) {}

    public function delete(int $knowledgeBaseId, int $documentId): void
    {
        $document = $this->documents->findByIdForKnowledgeBase($documentId, $knowledgeBaseId);

        if (!$document instanceof Document) {
            throw DocumentNotFound::inKnowledgeBase($documentId, $knowledgeBaseId);
        }

        // Mark deleted first: if the file removal fails, the document is already hidden and a later
        // sweep can reclaim the file, rather than leaving a "deleted" file with a live row.
        $this->documents->markDeleted($document->id());
        // Enqueue remote detach+delete of the vector-store files; the drainer does the network work.
        $this->indexedFiles->markPendingRemovalByDocument($document->id());
        $this->events->record($document->id(), 'deleted', 'Document removed by administrator.');

        $this->storage->delete($document->storedPath());
    }
}
