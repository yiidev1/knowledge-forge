<?php

declare(strict_types=1);

namespace App\Document\Application\Processing;

use App\Ai\OpenAi\OpenAiCredentials;
use App\Document\Domain\DocumentProcessingRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Log\SafeLogContext;
use App\Worker\Application\DrainerInterface;
use App\Worker\Application\DrainResult;
use Psr\Log\LoggerInterface;

/**
 * Drains queued and indexing documents toward `ready`.
 *
 * It processes a document only once its knowledge base's vector store is ready — otherwise it leaves the
 * document untouched (not claimed, so no attempt is burned waiting on provisioning). Each document is
 * claimed atomically before processing, so concurrent workers never handle the same one. If OpenAI is
 * unconfigured the drainer does nothing.
 */
final readonly class DocumentProcessingDrainer implements DrainerInterface
{
    public function __construct(
        private DocumentProcessingRepositoryInterface $documents,
        private KnowledgeBaseRepositoryInterface $knowledgeBases,
        private ProcessDocumentService $service,
        private RecoverStuckDocumentsService $recovery,
        private OpenAiCredentials $credentials,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private SafeLogContext $logContext,
    ) {}

    /**
     * Records a candidate this drainer declined to process.
     *
     * The readiness checks below are a safety net behind the SQL filter, so reaching one now means the
     * query and this code disagree — which is worth knowing. Previously every skip was silent, and a
     * queue that had stopped moving was indistinguishable from a queue with nothing left to do.
     *
     * Only numeric ids and a fixed reason string are written: no filename, no stored path, no document
     * content, no credentials.
     */
    private function logSkip(int $documentId, int $knowledgeBaseId, string $reason): void
    {
        $this->logger->info('document processing skipped', $this->logContext->build([
            'document_id' => $documentId,
            'knowledge_base_id' => $knowledgeBaseId,
            'reason' => $reason,
        ]));
    }

    public function name(): string
    {
        return 'document-processing';
    }

    public function recover(): void
    {
        $this->recovery->recover();
    }

    public function drain(int $limit): DrainResult
    {
        if (!$this->credentials->isComplete()) {
            return DrainResult::nothing();
        }

        $processed = 0;
        $failed = 0;

        foreach ($this->documents->findProcessable($limit, $this->clock->now()) as $candidate) {
            $knowledgeBase = $this->knowledgeBases->findById($candidate->knowledgeBaseId());

            // Wait for provisioning without claiming: no attempt is spent while the store is not ready.
            // The SQL filter already excludes these, so reaching here means the two disagree.
            if ($knowledgeBase === null || !$knowledgeBase->vectorStoreStatus()->isReady()) {
                $this->logSkip($candidate->id(), $candidate->knowledgeBaseId(), 'knowledge_base_not_ready');
                continue;
            }

            $vectorStoreId = $knowledgeBase->openaiVectorStoreId();
            if ($vectorStoreId === null) {
                $this->logSkip($candidate->id(), $candidate->knowledgeBaseId(), 'vector_store_id_missing');
                continue;
            }

            if (!$this->documents->claim($candidate->id(), $candidate->status(), $this->clock->now())) {
                // Normal under concurrency: another worker claimed it between the query and here.
                $this->logSkip($candidate->id(), $candidate->knowledgeBaseId(), 'claimed_by_another_worker');
                continue;
            }

            $claimed = $this->documents->find($candidate->id());
            if ($claimed === null) {
                $this->logSkip($candidate->id(), $candidate->knowledgeBaseId(), 'disappeared_after_claim');
                continue;
            }

            $outcome = $this->service->process($claimed, $vectorStoreId);

            if ($outcome === ProcessingOutcome::Failed) {
                $failed++;
            }
            if ($outcome->isTerminal()) {
                $processed++;
            }
        }

        return new DrainResult($processed, $failed);
    }
}
