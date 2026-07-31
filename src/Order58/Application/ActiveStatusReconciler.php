<?php

declare(strict_types=1);

namespace App\Order58\Application;

use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\Order58\Contract\ActiveFlag;
use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;

/**
 * Repairs stale Order58 store active status from the authoritative local snapshot, independent of
 * `_sync_hash`.
 *
 * Change detection means an unchanged store is only ever "marked seen" on a re-sync, so a mirror row whose
 * `active` column was written incorrectly by an earlier run would never be rewritten by a normal sync. The
 * curated snapshot, however, still holds the real `account.active` value, so this pass re-derives the flag
 * from the snapshot and corrects both the store mirror and its mapped knowledge base's `source_active`.
 *
 * It is deliberately conservative: it never calls Order58, never touches `agent_enabled`, the vector store,
 * documents or conversations, never regenerates a document, and only writes rows that are actually wrong.
 * Running it repeatedly with a consistent mirror corrects nothing.
 */
final readonly class ActiveStatusReconciler
{
    public function __construct(
        private Order58StoreRepositoryInterface $stores,
        private KnowledgeBaseSourceRepositoryInterface $knowledgeBases,
        private ClockInterface $clock,
    ) {}

    public function reconcile(): ReconcileReport
    {
        $now = $this->clock->now();
        $checked = 0;
        $storesCorrected = 0;
        $knowledgeBasesCorrected = 0;
        $skipped = 0;

        foreach ($this->stores->allMirrors() as $mirror) {
            $checked++;

            $desired = ActiveFlag::normalize($mirror->snapshot['active'] ?? null);
            if ($desired === null) {
                // The snapshot has no usable active flag — do not guess; leave the stored status untouched.
                $skipped++;

                continue;
            }

            if ($mirror->active !== $desired) {
                $this->stores->setActive($mirror->sourceId, $desired, $now);
                $storesCorrected++;
            }

            $knowledgeBaseId = $this->knowledgeBases->findIdBySource(
                EnsureStoreKnowledgeBaseService::SOURCE,
                $mirror->sourceId,
            );
            if ($knowledgeBaseId !== null && $this->knowledgeBases->reconcileSourceActive($knowledgeBaseId, $desired, $now)) {
                $knowledgeBasesCorrected++;
            }
        }

        return new ReconcileReport($checked, $storesCorrected, $knowledgeBasesCorrected, $skipped);
    }
}
