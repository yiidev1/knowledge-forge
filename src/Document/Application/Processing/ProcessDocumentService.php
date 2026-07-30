<?php

declare(strict_types=1);

namespace App\Document\Application\Processing;

use App\Ai\Contract\Dto\IndexStatus;
use App\Ai\Contract\Exception\AiException;
use App\Ai\Contract\KnowledgeIndexInterface;
use App\Document\Domain\Document;
use App\Document\Domain\DocumentProcessingRepositoryInterface;
use App\Document\Domain\Exception\ManualReviewRequired;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Document\Domain\ProcessingEventRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Worker\Application\WorkerParams;

/**
 * Drives one document through the ingestion state machine, without ever blocking on the provider.
 *
 * A document with no index files yet is on its first run: its processor produces the content (extracting
 * Markdown for images and scanned PDFs, which is persisted so a retry is free), the content is uploaded
 * and attached, and then it polls once. A document that already has index files is resumed: it just
 * polls their state. Each run does a single poll and defers — so a document reaches `ready` over several
 * cheap runs rather than one long blocking one, which suits a shared server.
 *
 * Failures are classified: a manual-review verdict or an exhausted/unrecoverable error fails the
 * document permanently; a transient error requeues it with backoff. All stored messages are redacted.
 */
final readonly class ProcessDocumentService
{
    private const ERROR_MAX = 1000;

    public function __construct(
        private DocumentProcessingRepositoryInterface $documents,
        private IndexedFileRepositoryInterface $indexedFiles,
        private KnowledgeIndexInterface $knowledgeIndex,
        private DocumentProcessorRegistry $registry,
        private ProcessingEventRepositoryInterface $events,
        private ClockInterface $clock,
        private SecretRedactor $redactor,
        private WorkerParams $params,
    ) {}

    /**
     * Never throws: any outcome, including an unexpected error, is recorded on the document so the
     * worker loop can trust a return value and move on to the next document.
     */
    public function process(Document $document, string $vectorStoreId): ProcessingOutcome
    {
        try {
            return $this->runStateMachine($document, $vectorStoreId);
        } catch (\Throwable) {
            // Unexpected (non-provider) failure — storage, database, a bug. Retry with backoff up to the
            // cap. The message is generic on purpose: a raw throwable could carry internal paths.
            return $this->onTransientMessage($document, 'processing_error', 'An unexpected error occurred while processing this document.');
        }
    }

    private function runStateMachine(Document $document, string $vectorStoreId): ProcessingOutcome
    {
        // Resuming an in-flight document: index files already exist, so just poll their state.
        if ($this->indexedFiles->findByDocument($document->id()) !== []) {
            return $this->pollAndFinalize($document, $vectorStoreId);
        }

        try {
            $indexables = $this->registry->for($document)->produce($document);
        } catch (ManualReviewRequired $e) {
            return $this->fail($document, $e->errorCode(), $e->getMessage());
        } catch (AiException $e) {
            return $this->onTransientOrPermanent($document, $e);
        }

        try {
            foreach ($indexables as $indexable) {
                $indexedFileId = $this->indexedFiles->createPending($document->id(), $indexable->role, $indexable->derivedPath);
                $result = $this->knowledgeIndex->indexContent(
                    $vectorStoreId,
                    $indexable->content,
                    $indexable->filename,
                    ['document_id' => (string) $document->id()],
                );
                $this->indexedFiles->setUploaded($indexedFileId, $result->openaiFileId, $result->state->status);
            }
        } catch (AiException $e) {
            // Clear the partial attempt so a retry re-produces cleanly; any uploaded-but-unrecorded file
            // is an orphan swept later by the GC command.
            $this->indexedFiles->deleteByDocument($document->id());

            return $this->onTransientOrPermanent($document, $e);
        }

        return $this->pollAndFinalize($document, $vectorStoreId);
    }

    private function pollAndFinalize(Document $document, string $vectorStoreId): ProcessingOutcome
    {
        $files = $this->indexedFiles->findByDocument($document->id());
        $allCompleted = true;
        $failureMessage = null;

        try {
            foreach ($files as $file) {
                if ($file->openaiFileId === null) {
                    $allCompleted = false;
                    continue;
                }

                $state = $this->knowledgeIndex->fileState($vectorStoreId, $file->openaiFileId);
                $this->indexedFiles->updateState($file->id, $state->status, $state->usageBytes, $state->errorCode, $state->errorMessage);

                if ($state->status === IndexStatus::Failed || $state->status === IndexStatus::Cancelled) {
                    $failureMessage = $state->errorMessage ?? 'Indexing failed.';
                } elseif ($state->status !== IndexStatus::Completed) {
                    $allCompleted = false;
                }
            }
        } catch (AiException $e) {
            return $this->onTransientOrPermanent($document, $e);
        }

        if ($failureMessage !== null) {
            return $this->fail($document, 'index_failed', $failureMessage);
        }

        if ($allCompleted && $files !== []) {
            $this->events->record($document->id(), 'ready', 'Document indexed and ready.');
            $this->documents->markReady($document->id(), $this->clock->now());

            return ProcessingOutcome::Ready;
        }

        $next = $this->clock->now()->modify('+' . $this->params->indexPollIntervalSeconds . ' seconds');
        $this->documents->markIndexing($document->id(), $next);

        return ProcessingOutcome::Indexing;
    }

    private function onTransientOrPermanent(Document $document, AiException $e): ProcessingOutcome
    {
        $details = $e->details();
        // A non-transient, non-ambiguous provider error will not fix itself; fail immediately.
        $unrecoverable = !$details->transient && !$details->sideEffectPossible;

        if ($unrecoverable) {
            return $this->fail($document, $details->code, $details->safeMessage);
        }

        return $this->onTransientMessage($document, $details->code, $details->safeMessage);
    }

    /**
     * Requeues with backoff, or fails once the attempt cap is reached.
     */
    private function onTransientMessage(Document $document, string $errorCode, string $message): ProcessingOutcome
    {
        if ($document->processingAttempts() >= $this->params->maxProcessingAttempts) {
            return $this->fail($document, $errorCode, $message);
        }

        $next = $this->clock->now()->modify('+' . $this->params->backoffSeconds($document->processingAttempts()) . ' seconds');
        $safe = $this->redactor->redactAndTruncate($message, self::ERROR_MAX);
        $this->events->record($document->id(), 'retry', $safe, ['error_code' => $errorCode]);
        $this->documents->requeue($document->id(), $next, $errorCode, $safe);

        return ProcessingOutcome::Requeued;
    }

    private function fail(Document $document, string $errorCode, string $message): ProcessingOutcome
    {
        $safe = $this->redactor->redactAndTruncate($message, self::ERROR_MAX);
        $this->events->record($document->id(), 'failed', $safe, ['error_code' => $errorCode]);
        $this->documents->markFailed($document->id(), $errorCode, $safe);

        return ProcessingOutcome::Failed;
    }
}
