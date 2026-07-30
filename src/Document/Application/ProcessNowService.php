<?php

declare(strict_types=1);

namespace App\Document\Application;

use App\Document\Domain\Document;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\Exception\DocumentActionNotAllowed;
use App\Document\Domain\Exception\DocumentNotFound;
use App\Document\Domain\ProcessingEventRepositoryInterface;

/**
 * "Process now" — raises a queued document's priority and clears its backoff so the next worker run
 * picks it up first. Enqueue-only: it never calls OpenAI. Only an in-progress (queued/processing/
 * indexing) document can be expedited; a ready or failed one has nothing to bring forward.
 */
final readonly class ProcessNowService
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private ProcessingEventRepositoryInterface $events,
    ) {}

    public function processNow(int $knowledgeBaseId, int $documentId): void
    {
        $document = $this->documents->findByIdForKnowledgeBase($documentId, $knowledgeBaseId);

        if (!$document instanceof Document) {
            throw DocumentNotFound::inKnowledgeBase($documentId, $knowledgeBaseId);
        }

        if (!$document->status()->isInProgress()) {
            throw DocumentActionNotAllowed::forStatus('expedited', $document->status());
        }

        $this->documents->bumpPriority($document->id());
        $this->events->record($document->id(), $document->status()->value, 'Prioritised by administrator.');
    }
}
