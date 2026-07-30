<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

use App\Document\Domain\DocumentRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;
use App\KnowledgeBase\Domain\VectorStoreStatus;

use function usort;

/**
 * Lines up remote vector stores against local knowledge bases and classifies every difference.
 *
 * Read-only and deliberately inert: it reports, it never repairs. An orphaned remote store is not
 * necessarily rubbish — another environment may share the same OpenAI account — and a knowledge base
 * whose store has vanished needs a human to decide between re-provisioning and restoring. Automating
 * either from a dashboard would turn a diagnostic into a destructive tool.
 *
 * Archived knowledge bases are included. An archived base still owns a store that is still being billed,
 * which is exactly the kind of cost an operator opens this page to find.
 */
final readonly class UsageReconciler
{
    public function __construct(
        private KnowledgeBaseRepositoryInterface $knowledgeBases,
        private DocumentRepositoryInterface $documents,
    ) {}

    /**
     * @param list<UsageStoreRow> $remoteStores
     *
     * @return list<UsageMapping>
     */
    public function reconcile(array $remoteStores): array
    {
        $remoteById = [];
        foreach ($remoteStores as $store) {
            $remoteById[$store->id] = $store;
        }

        $mappings = [];
        $claimedRemoteIds = [];

        foreach ($this->knowledgeBases->findAll(includeArchived: true) as $knowledgeBase) {
            $storeId = $knowledgeBase->openaiVectorStoreId();
            $localStatus = $knowledgeBase->vectorStoreStatus();

            if ($storeId === null || $storeId === '') {
                $mappings[] = new UsageMapping(
                    state: UsageMapping::STATE_NOT_PROVISIONED,
                    knowledgeBaseId: $knowledgeBase->id(),
                    knowledgeBaseName: $knowledgeBase->name(),
                    knowledgeBaseSlug: $knowledgeBase->slug(),
                    localVectorStoreStatus: $localStatus->value,
                    localDocumentCount: $this->documents->countLiveForKnowledgeBase($knowledgeBase->id()),
                    localReadyDocumentCount: $this->documents->countReadyForKnowledgeBase($knowledgeBase->id()),
                    archived: $knowledgeBase->isArchived(),
                );

                continue;
            }

            $remote = $remoteById[$storeId] ?? null;
            $claimedRemoteIds[$storeId] = true;

            $state = match (true) {
                $remote === null => UsageMapping::STATE_LOCAL_MISSING_REMOTE,
                // Locally "ready" but the provider does not say "completed" (or vice versa) means one
                // side acted on information the other does not have — worth a human look.
                $this->statusesDisagree($localStatus, $remote->status) => UsageMapping::STATE_STATUS_MISMATCH,
                default => UsageMapping::STATE_MATCHED,
            };

            $mappings[] = new UsageMapping(
                state: $state,
                knowledgeBaseId: $knowledgeBase->id(),
                knowledgeBaseName: $knowledgeBase->name(),
                knowledgeBaseSlug: $knowledgeBase->slug(),
                localVectorStoreStatus: $localStatus->value,
                remoteVectorStoreId: $storeId,
                remoteStatus: $remote?->status,
                localDocumentCount: $this->documents->countLiveForKnowledgeBase($knowledgeBase->id()),
                localReadyDocumentCount: $this->documents->countReadyForKnowledgeBase($knowledgeBase->id()),
                remoteFileCount: $remote?->fileCounts->total,
                archived: $knowledgeBase->isArchived(),
            );
        }

        // Remote stores nothing local claims. Reported, never touched.
        foreach ($remoteStores as $store) {
            if (!isset($claimedRemoteIds[$store->id])) {
                $mappings[] = new UsageMapping(
                    state: UsageMapping::STATE_REMOTE_UNMAPPED,
                    remoteVectorStoreId: $store->id,
                    remoteStatus: $store->status,
                    remoteFileCount: $store->fileCounts->total,
                );
            }
        }

        // Problems first: the reason to open this section is to find them, not to scroll past the
        // healthy rows looking for them.
        usort($mappings, static function (UsageMapping $a, UsageMapping $b): int {
            return [$a->isProblem() ? 0 : 1, $a->knowledgeBaseName ?? $a->remoteVectorStoreId ?? '']
                <=> [$b->isProblem() ? 0 : 1, $b->knowledgeBaseName ?? $b->remoteVectorStoreId ?? ''];
        });

        return $mappings;
    }

    /**
     * A local `ready` must correspond to a remote `completed`; anything else is a disagreement worth
     * flagging. Non-ready local states are still in flight, so they are not compared.
     */
    private function statusesDisagree(VectorStoreStatus $local, string $remoteStatus): bool
    {
        return $local === VectorStoreStatus::Ready && $remoteStatus !== 'completed';
    }
}
