<?php

declare(strict_types=1);

namespace App\Document\Application\Processing;

use App\Ai\OpenAi\OpenAiCredentials;
use App\Document\Domain\DocumentProcessingRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Worker\Application\DrainerInterface;
use App\Worker\Application\DrainResult;

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
    ) {}

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
            if ($knowledgeBase === null || !$knowledgeBase->vectorStoreStatus()->isReady()) {
                continue;
            }

            $vectorStoreId = $knowledgeBase->openaiVectorStoreId();
            if ($vectorStoreId === null) {
                continue;
            }

            if (!$this->documents->claim($candidate->id(), $candidate->status(), $this->clock->now())) {
                continue; // another worker took it
            }

            $claimed = $this->documents->find($candidate->id());
            if ($claimed === null) {
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
