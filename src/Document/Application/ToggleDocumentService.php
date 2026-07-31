<?php

declare(strict_types=1);

namespace App\Document\Application;

use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\Exception\DocumentNotFound;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Document\Domain\ProcessingEventRepositoryInterface;
use App\Document\Domain\TextDocumentRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;

/**
 * Enables or disables a document for retrieval — for any source type.
 *
 * Disabling hides the document from chat: its vector-store file is flagged for removal (the cleanup
 * drainer detaches it) and it stops counting toward chat readiness, while its row, history and stored
 * text are all preserved. Enabling requeues it so the worker re-indexes it back into the vector store.
 * The desired state is passed explicitly, so a double submission is idempotent.
 */
final readonly class ToggleDocumentService
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private TextDocumentRepositoryInterface $texts,
        private IndexedFileRepositoryInterface $indexedFiles,
        private ProcessingEventRepositoryInterface $events,
        private ClockInterface $clock,
    ) {}

    public function setEnabled(int $documentId, int $knowledgeBaseId, bool $enabled): void
    {
        if ($this->documents->findByIdForKnowledgeBase($documentId, $knowledgeBaseId) === null) {
            throw DocumentNotFound::inKnowledgeBase($documentId, $knowledgeBaseId);
        }

        $this->texts->setEnabled($documentId, $knowledgeBaseId, $enabled, $this->clock->now());

        if ($enabled) {
            $this->documents->requeueFresh($documentId);
            $this->events->record($documentId, 'queued', 'Document enabled and queued for re-indexing.');

            return;
        }

        $this->indexedFiles->markPendingRemovalByDocument($documentId);
        $this->events->record($documentId, 'disabled', 'Document disabled and removed from retrieval.');
    }
}
