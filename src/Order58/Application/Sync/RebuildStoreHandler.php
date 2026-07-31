<?php

declare(strict_types=1);

namespace App\Order58\Application\Sync;

use App\Document\Domain\DocumentSourceType;
use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\Order58\Application\EnsureStoreKnowledgeBaseService;
use App\Order58\Application\Formatter\Order58KnowledgeFormatter;
use App\Order58\Application\Formatter\Order58StoreProfileFormatter;
use App\Order58\Application\SyncDocumentService;
use App\Order58\Domain\Order58KnowledgeRepositoryInterface;
use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Order58\Domain\SyncRun;
use DateTimeImmutable;

/**
 * Regenerates one store's generated documents (store profile + all its active knowledge) from the local
 * mirror, forcing a rewrite and re-index regardless of `_sync_hash`. Makes no Order58 API call — useful
 * after a knowledge base has been re-provisioned or its documents were lost.
 */
final readonly class RebuildStoreHandler implements Order58SyncHandlerInterface
{
    public function __construct(
        private Order58StoreRepositoryInterface $stores,
        private Order58KnowledgeRepositoryInterface $knowledge,
        private KnowledgeBaseSourceRepositoryInterface $knowledgeBases,
        private Order58StoreProfileFormatter $storeFormatter,
        private Order58KnowledgeFormatter $knowledgeFormatter,
        private SyncDocumentService $documents,
    ) {}

    public function handle(SyncRun $run, DateTimeImmutable $now): SyncOutcome
    {
        $progress = $run->progress();
        $storeId = $run->scopeRef();

        if ($storeId === null) {
            return SyncOutcome::failed($progress, 'invalid_scope', 'Rebuild requires a store.');
        }

        $store = $this->stores->findBySourceId($storeId);
        if ($store === null) {
            return SyncOutcome::failed($progress, 'store_missing', 'Store not found in the local mirror.');
        }

        $knowledgeBaseId = $this->knowledgeBases->findIdBySource(EnsureStoreKnowledgeBaseService::SOURCE, $storeId);
        if ($knowledgeBaseId === null) {
            return SyncOutcome::completedWithWarnings($progress, KnowledgeSyncHandler::MISSING_STORE_MESSAGE);
        }

        $this->documents->upsertGenerated(
            $knowledgeBaseId,
            DocumentSourceType::Order58StoreProfile,
            (string) $storeId,
            'Store profile: ' . $store->name,
            $store->syncHash,
            $this->storeFormatter->format($store->snapshot),
            $now,
            force: true,
        );
        $progress->updated++;
        $progress->documentsQueued++;

        foreach ($this->knowledge->findAllForStore($storeId) as $record) {
            $title = $record->title !== '' ? $record->title : ('Knowledge #' . $record->sourceId);
            $this->documents->upsertGenerated(
                $knowledgeBaseId,
                DocumentSourceType::Order58Knowledge,
                (string) $record->sourceId,
                $title,
                $record->syncHash,
                $this->knowledgeFormatter->format(
                    $record->storeSourceId,
                    $record->sourceId,
                    $record->title,
                    $record->content,
                    $record->type,
                    $record->keyword,
                    $record->knowledgeNumber,
                ),
                $now,
                force: true,
            );
            $progress->updated++;
            $progress->documentsQueued++;
        }

        return SyncOutcome::completed($progress);
    }
}
