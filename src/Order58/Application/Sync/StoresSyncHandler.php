<?php

declare(strict_types=1);

namespace App\Order58\Application\Sync;

use App\Document\Domain\DocumentSourceType;
use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\Order58\Application\EnsureStoreKnowledgeBaseService;
use App\Order58\Application\Formatter\Order58StoreProfileFormatter;
use App\Order58\Application\Mapper\StoreMapper;
use App\Order58\Application\Order58SyncParams;
use App\Order58\Application\SyncDocumentService;
use App\Order58\Contract\Dto\Order58Account;
use App\Order58\Contract\Exception\Order58Exception;
use App\Order58\Contract\Order58ClientInterface;
use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Order58\Domain\SyncProgress;
use App\Order58\Domain\SyncRun;
use App\Order58\Domain\SyncRunRepositoryInterface;
use DateTimeImmutable;

/**
 * Full paginated scan of Order58 accounts. Mirrors each store, ensures its one knowledge base, and
 * regenerates the store-profile document only when the store's `_sync_hash` changed. After the final page
 * succeeds, deactivates stores that vanished from the source and marks their knowledge bases' source
 * inactive (their vector stores and history are preserved). Does not touch knowledge or agents.
 */
final readonly class StoresSyncHandler implements Order58SyncHandlerInterface
{
    public function __construct(
        private Order58ClientInterface $client,
        private Order58StoreRepositoryInterface $stores,
        private KnowledgeBaseSourceRepositoryInterface $knowledgeBases,
        private EnsureStoreKnowledgeBaseService $ensureKnowledgeBase,
        private StoreMapper $mapper,
        private Order58StoreProfileFormatter $formatter,
        private SyncDocumentService $documents,
        private SyncRunRepositoryInterface $runs,
        private Order58SyncParams $params,
    ) {}

    public function handle(SyncRun $run, DateTimeImmutable $now): SyncOutcome
    {
        $progress = $run->progress();
        $runId = $run->id();
        $pagesThisRun = 0;

        while (true) {
            try {
                $page = $this->client->listAccounts($progress->nextPage, $this->params->pageSize);
            } catch (Order58Exception $e) {
                return $e->isTransient()
                    ? SyncOutcome::transient($progress, $e->details()->code, $e->getMessage())
                    : SyncOutcome::failed($progress, $e->details()->code, $e->getMessage());
            }

            foreach ($page->items as $account) {
                $this->processStore($account, $runId, $progress, $now);
            }

            $progress->pagesProcessed++;
            $progress->nextPage++;
            $pagesThisRun++;
            $this->runs->saveProgress($runId, $progress, $now);

            if (!$page->pagination->hasMore()) {
                break;
            }
            if ($pagesThisRun >= $this->params->pagesPerRun) {
                return SyncOutcome::partial($progress);
            }
        }

        // Final page succeeded — safe to sweep stores the source no longer returns.
        foreach ($this->stores->deactivateNotSeen($runId, $now) as $storeId) {
            $kbId = $this->knowledgeBases->findIdBySource(EnsureStoreKnowledgeBaseService::SOURCE, $storeId);
            if ($kbId !== null) {
                $this->knowledgeBases->markSourceInactive($kbId, $now);
            }
            $progress->deactivated++;
        }

        return $progress->warnings > 0
            ? SyncOutcome::completedWithWarnings(
                $progress,
                'Some stores were returned without a valid "active" flag and were left unchanged.',
            )
            : SyncOutcome::completed($progress);
    }

    private function processStore(Order58Account $account, int $runId, SyncProgress $progress, DateTimeImmutable $now): void
    {
        $existingHash = $this->stores->findSyncHash($account->id);
        if ($existingHash === $account->syncHash) {
            // Unchanged: stamp seen only (so the sweep never deactivates it); no rewrite, no re-index.
            $this->stores->markSeen($account->id, $runId, $now);
            $progress->unchanged++;

            return;
        }

        if (!$account->activeKnown) {
            // The record arrived without a recognisable active flag. Never guess "inactive": stamp it seen
            // so the sweep preserves it and leave any existing local status untouched, then warn.
            $this->stores->markSeen($account->id, $runId, $now);
            $progress->warnings++;

            return;
        }

        $mirror = $this->mapper->toMirror($account);
        $this->stores->save($mirror, $runId, $now);
        $knowledgeBaseId = $this->ensureKnowledgeBase->ensure($account->id, $account->name, $account->active, $now);

        $result = $this->documents->upsertGenerated(
            $knowledgeBaseId,
            DocumentSourceType::Order58StoreProfile,
            (string) $account->id,
            'Store profile: ' . $account->name,
            $account->syncHash,
            $this->formatter->format($mirror->snapshot),
            $now,
        );

        if ($existingHash === null) {
            $progress->created++;
        } else {
            $progress->updated++;
        }
        if ($result->queuedIndexing()) {
            $progress->documentsQueued++;
        } else {
            $progress->documentsSkippedUnchanged++;
        }
    }
}
