<?php

declare(strict_types=1);

namespace App\Document\Application;

use App\Ai\Contract\Exception\AiException;
use App\Ai\Contract\KnowledgeIndexInterface;
use App\Ai\OpenAi\OpenAiCredentials;
use App\Document\Domain\DocumentProcessingRepositoryInterface;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;
use App\Worker\Application\DrainerInterface;
use App\Worker\Application\DrainResult;

/**
 * Detaches and deletes the OpenAI files left behind by deleted or re-indexed documents.
 *
 * Delete and re-index only flag the index-file rows (`pending_removal`) in the web request; the actual
 * remote detach+delete is network work and belongs here. A transient provider error leaves the row for
 * the next run; a permanent one (typically the file is already gone) drops the row anyway, so cleanup
 * never loops forever. If OpenAI is unconfigured the drainer does nothing.
 */
final readonly class RemoteCleanupDrainer implements DrainerInterface
{
    public function __construct(
        private IndexedFileRepositoryInterface $indexedFiles,
        private DocumentProcessingRepositoryInterface $documents,
        private KnowledgeBaseRepositoryInterface $knowledgeBases,
        private KnowledgeIndexInterface $knowledgeIndex,
        private OpenAiCredentials $credentials,
    ) {}

    public function name(): string
    {
        return 'remote-cleanup';
    }

    public function recover(): void
    {
        // Nothing to recover: flagged rows simply wait to be drained, and the remote call is retried
        // from scratch each run — there is no interruptible mid-flight state to reset.
    }

    public function drain(int $limit): DrainResult
    {
        if (!$this->credentials->isComplete()) {
            return DrainResult::nothing();
        }

        $processed = 0;
        $failed = 0;

        foreach ($this->indexedFiles->findPendingRemoval($limit) as $file) {
            // Never uploaded — nothing remote to remove, so just drop the row.
            if ($file->openaiFileId === null) {
                $this->indexedFiles->delete($file->id);
                $processed++;
                continue;
            }

            $vectorStoreId = $this->resolveVectorStoreId($file->documentId);
            if ($vectorStoreId === null) {
                // No store to detach from; the file could never have been attached. Drop the row.
                $this->indexedFiles->delete($file->id);
                $processed++;
                continue;
            }

            try {
                $this->knowledgeIndex->removeFile($vectorStoreId, $file->openaiFileId);
                $this->indexedFiles->delete($file->id);
                $processed++;
            } catch (AiException $e) {
                if ($e->details()->transient) {
                    // Leave the row flagged; a later run retries it.
                    $failed++;
                    continue;
                }

                // Permanent (e.g. the file is already gone): consider it removed and drop the row.
                $this->indexedFiles->delete($file->id);
                $processed++;
            }
        }

        return new DrainResult($processed, $failed);
    }

    private function resolveVectorStoreId(int $documentId): ?string
    {
        $document = $this->documents->find($documentId);
        if ($document === null) {
            return null;
        }

        return $this->knowledgeBases->findById($document->knowledgeBaseId())?->openaiVectorStoreId();
    }
}
