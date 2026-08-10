<?php

declare(strict_types=1);

namespace App\Rules\Application;

use App\Document\Domain\DocumentSourceType;
use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\Order58\Application\EnsureStoreKnowledgeBaseService;
use App\Order58\Application\SyncDocumentService;
use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Rules\Contract\RuleCatalogRepositoryInterface;
use App\Rules\Contract\RuleStoreLinkRepositoryInterface;
use App\Rules\Domain\ClassificationStatus;
use DateTimeImmutable;

use function in_array;

/**
 * Materializes a canonical rule's searchable projection(s) to match its current classification, and retires
 * projections that no longer apply. All document work is queued through the existing generated-document
 * pipeline (no OpenAI call here); the worker indexes it, and the replacement guard keeps the old vector-store
 * file until the new one completes.
 *
 * **Classification is separate from retrieval availability.** A rule keeps its store scope and mapping for
 * reporting, but retrieval is governed by the explicit `is_globally_available` flag (default on for every active
 * rule). For a canonical rule that is active:
 *  - **Global projection** (`order58_rule_global` in the hidden Global Rules base): created for EVERY active,
 *    globally-available rule, whatever its classification (store-specific, common, ambiguous, unmatched or
 *    pending). This is the single searchable corpus that backs Rule Chat.
 *  - **Store projection** (`order58_rule_store` in a store KB): NO LONGER CREATED. Store chat must answer only
 *    from genuine store knowledge, so rule documents are not projected into store vector stores; any that were
 *    projected in the past are retired here (and swept fleet-wide by `kf:rules:retire-store-projections`).
 *  - An inactive (upstream-removed) rule, or one an admin ignored/disabled (`is_globally_available = 0`), is
 *    NOT globally available and its global projection is retired.
 *
 * Retirement is comprehensive and idempotent: ignoring, disabling global availability, or upstream deactivation
 * retires the global projection; every store projection this rule ever had is retired unconditionally. A repeated
 * pass over unchanged data creates and disables nothing new. The legacy `order58_rule_common` projection is
 * superseded by `order58_rule_global` and retired on sight. The canonical rule catalog is never modified here.
 */
final readonly class RuleProjectionReconciler
{
    private const STORE_SPECIFIC_STATUSES = [ClassificationStatus::AutoMatched, ClassificationStatus::ManuallyMatched];

    public function __construct(
        private RuleCatalogRepositoryInterface $catalog,
        private RuleStoreLinkRepositoryInterface $links,
        private EnsureCommonRulesKnowledgeBaseService $globalBase,
        private KnowledgeBaseSourceRepositoryInterface $knowledgeBases,
        private SyncDocumentService $documents,
        private RuleDocumentRenderer $renderer,
        private Order58StoreRepositoryInterface $stores,
    ) {}

    public function reconcile(int $canonicalId, DateTimeImmutable $now): void
    {
        $rule = $this->catalog->findClassification($canonicalId);
        if ($rule === null) {
            return;
        }

        $status = ClassificationStatus::tryFrom($rule['classification_status']);
        $ref = (string) $canonicalId;
        $title = $rule['title'];
        $body = $rule['content'];
        $syncHash = $rule['canonical_hash'];

        // An inactive (upstream-removed) rule is never searchable, whatever its last classification was.
        $active = $rule['is_active'];
        $confirmedStoreIds = $this->links->findConfirmedStoreIds($canonicalId);

        $isStoreSpecific = $status !== null && in_array($status, self::STORE_SPECIFIC_STATUSES, true);

        // The store projection exists only for a store-specific rule with a confirmed (deterministic or admin) store.
        $materializeStore = $active && $isStoreSpecific && $confirmedStoreIds !== [];
        // The global projection exists for EVERY active, globally-available rule — classification does not gate it.
        $globallyAvailable = $active && $rule['is_globally_available'];
        $globalScope = match ($status) {
            ClassificationStatus::ConfirmedCommon => 'Common',
            ClassificationStatus::AutoMatched, ClassificationStatus::ManuallyMatched => 'Store-specific',
            default => 'General',
        };
        // $materializeStore is only true when $confirmedStoreIds is non-empty, so [0] is always defined here.
        $globalStoreName = $materializeStore ? $this->storeName($confirmedStoreIds[0]) : null;

        $this->reconcileGlobal($globallyAvailable, $ref, $title, $body, $syncHash, $globalScope, $globalStoreName, $now);
        $this->reconcileStores($canonicalId, $ref, $now);
    }

    private function reconcileGlobal(bool $materialize, string $ref, string $title, string $body, string $syncHash, string $scope, ?string $storeName, DateTimeImmutable $now): void
    {
        // Only touch the hidden base if it must exist (materialize) or already exists (retire).
        $baseId = $materialize ? $this->globalBase->ensure() : $this->globalBase->find()?->id();
        if ($baseId === null) {
            return;
        }

        // The legacy per-common projection is superseded by the unified global projection — always retire it.
        $this->documents->disableGenerated($baseId, DocumentSourceType::Order58RuleCommon, $ref, $now);

        if ($materialize) {
            $this->documents->upsertGenerated(
                $baseId,
                DocumentSourceType::Order58RuleGlobal,
                $ref,
                $title,
                $syncHash,
                $this->renderer->render($title, $body, $scope, $storeName),
                $now,
            );

            return;
        }

        $this->documents->disableGenerated($baseId, DocumentSourceType::Order58RuleGlobal, $ref, $now);
    }

    /**
     * Store-rule documents are no longer projected into store knowledge bases — store chat must answer only from
     * genuine store knowledge. Any store document previously projected for this rule (in any linked store) is
     * retired through the standard disable → pending-removal → worker-cleanup path (no OpenAI call here). The
     * canonical rule and its global projection are left untouched. Idempotent: already-retired documents no-op.
     */
    private function reconcileStores(int $canonicalId, string $ref, DateTimeImmutable $now): void
    {
        foreach ($this->links->findLinkedStoreIds($canonicalId) as $storeId) {
            $kbId = $this->knowledgeBases->findIdBySource(EnsureStoreKnowledgeBaseService::SOURCE, $storeId);
            if ($kbId !== null) {
                $this->documents->disableGenerated($kbId, DocumentSourceType::Order58RuleStore, $ref, $now);
            }
        }
    }

    private function storeName(int $storeSourceId): ?string
    {
        return $this->stores->findBySourceId($storeSourceId)?->name;
    }
}
