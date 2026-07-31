<?php

declare(strict_types=1);

namespace App\Order58\Application\Sync;

use App\Document\Domain\DocumentSourceType;
use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\Order58\Application\EnsureStoreKnowledgeBaseService;
use App\Order58\Application\Formatter\Order58KnowledgeFormatter;
use App\Order58\Application\GeneratedDocumentUpsert;
use App\Order58\Application\Mapper\KnowledgeMapper;
use App\Order58\Application\Order58SyncParams;
use App\Order58\Application\SyncDocumentService;
use App\Order58\Contract\Dto\Order58KnowledgeRecord;
use App\Order58\Contract\Exception\Order58Exception;
use App\Order58\Contract\Order58ClientInterface;
use App\Order58\Domain\Order58KnowledgeRepositoryInterface;
use App\Order58\Domain\SyncProgress;
use App\Order58\Domain\SyncRun;
use App\Order58\Domain\SyncRunRepositoryInterface;
use DateTimeImmutable;

/**
 * Full paginated scan of Order58 knowledge (or one store's knowledge when the run is scoped). Mirrors each
 * record and, when its owning store's knowledge base exists, generates/updates the knowledge document.
 *
 * A record whose store has not been synced yet is still mirrored and marked seen (so the final sweep never
 * deactivates a record the source still returns) but produces no document and no knowledge base — the run
 * finishes `completed_with_warnings`. Once the store is later synced, the next run generates the document.
 * The sweep is scoped: a per-store run only ever evaluates that store's records.
 */
final readonly class KnowledgeSyncHandler implements Order58SyncHandlerInterface
{
    public const MISSING_STORE_MESSAGE = 'Run Sync Stores first.';

    public function __construct(
        private Order58ClientInterface $client,
        private Order58KnowledgeRepositoryInterface $knowledge,
        private KnowledgeBaseSourceRepositoryInterface $knowledgeBases,
        private KnowledgeMapper $mapper,
        private Order58KnowledgeFormatter $formatter,
        private SyncDocumentService $documents,
        private SyncRunRepositoryInterface $runs,
        private Order58SyncParams $params,
    ) {}

    public function handle(SyncRun $run, DateTimeImmutable $now): SyncOutcome
    {
        $progress = $run->progress();
        $runId = $run->id();
        $storeScope = $run->scopeRef();
        $pagesThisRun = 0;

        while (true) {
            try {
                $page = $this->client->listKnowledge($storeScope, $progress->nextPage, $this->params->pageSize);
            } catch (Order58Exception $e) {
                return $e->isTransient()
                    ? SyncOutcome::transient($progress, $e->details()->code, $e->getMessage())
                    : SyncOutcome::failed($progress, $e->details()->code, $e->getMessage());
            }

            foreach ($page->items as $record) {
                $this->processRecord($record, $runId, $progress, $now);
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

        $deactivated = $storeScope === null
            ? $this->knowledge->deactivateNotSeen($runId, $now)
            : $this->knowledge->deactivateNotSeenForStore($storeScope, $runId, $now);

        foreach ($deactivated as $record) {
            $kbId = $this->knowledgeBases->findIdBySource(EnsureStoreKnowledgeBaseService::SOURCE, $record['store_source_id']);
            if ($kbId !== null) {
                $this->documents->disableGenerated(
                    $kbId,
                    DocumentSourceType::Order58Knowledge,
                    (string) $record['source_id'],
                    $now,
                );
            }
            $progress->deactivated++;
        }

        return $progress->warnings > 0
            ? SyncOutcome::completedWithWarnings($progress, self::MISSING_STORE_MESSAGE)
            : SyncOutcome::completed($progress);
    }

    private function processRecord(Order58KnowledgeRecord $record, int $runId, SyncProgress $progress, DateTimeImmutable $now): void
    {
        $knowledgeBaseId = $this->knowledgeBases->findIdBySource(EnsureStoreKnowledgeBaseService::SOURCE, $record->storeId);
        $existingHash = $this->knowledge->findSyncHash($record->id);
        $hashUnchanged = $existingHash === $record->syncHash;

        if ($knowledgeBaseId === null) {
            // Missing store: mirror and mark seen (never deactivate a record the source still returns), but
            // generate no document and create no knowledge base.
            if ($hashUnchanged) {
                $this->knowledge->markSeen($record->id, $runId, $now);
            } else {
                $this->knowledge->save($this->mapper->toMirror($record), $runId, $now);
            }
            $progress->skippedMissingStore++;
            $progress->warnings++;

            return;
        }

        if ($hashUnchanged) {
            $this->knowledge->markSeen($record->id, $runId, $now);
        } else {
            $this->knowledge->save($this->mapper->toMirror($record), $runId, $now);
        }

        // Always reconcile the generated document: this is a no-op when it already matches this sync hash,
        // but it creates the document when a previously store-less record can finally be indexed.
        $title = $record->title !== '' ? $record->title : ('Knowledge #' . $record->id);
        $result = $this->documents->upsertGenerated(
            $knowledgeBaseId,
            DocumentSourceType::Order58Knowledge,
            (string) $record->id,
            $title,
            $record->syncHash,
            $this->formatter->format(
                $record->storeId,
                $record->id,
                $record->title,
                $record->content,
                $record->type,
                $record->keyword,
                $record->knowledgeNumber,
            ),
            $now,
        );

        if ($hashUnchanged && $result === GeneratedDocumentUpsert::Unchanged) {
            $progress->unchanged++;

            return;
        }

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
