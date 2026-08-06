<?php

declare(strict_types=1);

namespace App\Rules\Application;

use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Rules\Contract\RuleCatalogRepositoryInterface;
use App\Rules\Contract\StoreAliasRepositoryInterface;
use App\Rules\Domain\ClassificationContext;
use DateTimeImmutable;

use function array_map;

/**
 * Drives classification during a rules sync: seeds aliases from the current store mirror, builds the shared
 * {@see ClassificationContext} once, and classifies the canonical rule behind each processed source record.
 * All work is idempotent, so repeated syncs of unchanged data create no rows.
 */
final readonly class RuleClassificationRunner
{
    public function __construct(
        private StoreAliasSeeder $seeder,
        private StoreAliasRepositoryInterface $aliases,
        private Order58StoreRepositoryInterface $stores,
        private RuleCatalogRepositoryInterface $catalog,
        private RuleClassificationService $service,
        private RuleProjectionReconciler $reconciler,
    ) {}

    /**
     * Seeds aliases from the current stores and returns the context (known store ids + approved aliases).
     */
    public function prepare(DateTimeImmutable $now): ClassificationContext
    {
        $this->seeder->seed($now);

        $storeIds = array_map(static fn($store): int => $store->sourceId, $this->stores->allMirrors());

        return new ClassificationContext($storeIds, $this->aliases->findApprovedAliases());
    }

    public function classifyForRecord(int $order58RuleRecordId, ClassificationContext $context, DateTimeImmutable $now): void
    {
        $canonicalId = $this->catalog->findCanonicalIdForRecord($order58RuleRecordId);
        if ($canonicalId === null) {
            return;
        }

        $this->service->classify($canonicalId, $context, $now);
        // Reconcile the searchable projection to match the (possibly changed) classification — idempotent, and
        // only approved rules produce documents.
        $this->reconciler->reconcile($canonicalId, $now);
    }

    /**
     * Reconciles the projection for the canonical rule behind a raw record after a mark-and-sweep, so an
     * upstream-removed (now-inactive) rule has both its store and global documents retired. Idempotent.
     */
    public function reconcileForRecord(int $order58RuleRecordId, DateTimeImmutable $now): void
    {
        $canonicalId = $this->catalog->findCanonicalIdForRecord($order58RuleRecordId);
        if ($canonicalId !== null) {
            $this->reconciler->reconcile($canonicalId, $now);
        }
    }
}
