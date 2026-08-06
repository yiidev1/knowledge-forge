<?php

declare(strict_types=1);

namespace App\Rules\Application;

use App\Rules\Contract\RuleCatalogRepositoryInterface;
use App\Rules\Contract\RuleClassificationEventRepositoryInterface;
use App\Rules\Contract\RuleStoreLinkRepositoryInterface;
use App\Rules\Domain\ClassificationStatus;
use App\Rules\Domain\RuleScope;
use App\Rules\Domain\StoreMatchStatus;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Domain\Exception\NotFoundException;

/**
 * Admin review decisions on canonical rules. Every action records an audit event AND immediately reconciles the
 * searchable projection (no full re-sync needed) — creating, updating, moving or retiring the document — while
 * NEVER calling OpenAI in the request (materialization only queues local document work for the worker).
 *
 * Admin decisions set an admin-owned classification/link that a later automated sync will not overwrite.
 */
final readonly class RuleReviewService
{
    public function __construct(
        private RuleCatalogRepositoryInterface $catalog,
        private RuleStoreLinkRepositoryInterface $links,
        private RuleClassificationEventRepositoryInterface $events,
        private RuleProjectionReconciler $reconciler,
        private ClockInterface $clock,
    ) {}

    public function markCommon(int $canonicalId, int $adminId): void
    {
        $this->transition($canonicalId, RuleScope::Common, ClassificationStatus::ConfirmedCommon, 'marked_common', $adminId);
    }

    public function markUnresolved(int $canonicalId, int $adminId): void
    {
        $this->transition($canonicalId, RuleScope::Unresolved, ClassificationStatus::Pending, 'marked_unresolved', $adminId);
    }

    public function ignore(int $canonicalId, int $adminId): void
    {
        $this->transition($canonicalId, RuleScope::Unresolved, ClassificationStatus::Ignored, 'marked_ignored', $adminId);
    }

    public function confirmStore(int $canonicalId, int $storeSourceId, int $adminId): void
    {
        $current = $this->require($canonicalId);
        $now = $this->clock->now();

        $this->links->setAdminLink($canonicalId, $storeSourceId, StoreMatchStatus::Confirmed, $adminId, $now);
        $this->catalog->updateClassification($canonicalId, RuleScope::StoreSpecific->value, ClassificationStatus::ManuallyMatched->value, 'admin confirmed store match', null, $adminId, $now);
        // A positive classification action keeps the rule globally available (the global projection is preserved).
        $this->catalog->setGloballyAvailable($canonicalId, true, $now);
        $this->events->record($canonicalId, 'store_match_confirmed', $current['classification_status'], ClassificationStatus::ManuallyMatched->value, null, ['store_source_id' => $storeSourceId], $adminId, $now);
        $this->reconciler->reconcile($canonicalId, $now);
    }

    public function rejectStore(int $canonicalId, int $storeSourceId, int $adminId): void
    {
        $current = $this->require($canonicalId);
        $now = $this->clock->now();

        $this->links->setAdminLink($canonicalId, $storeSourceId, StoreMatchStatus::Rejected, $adminId, $now);

        // If no confirmed store remains, the rule falls back to pending review (never silently "common"). It stays
        // globally available — losing a store mapping only retires the store projection, not the global one.
        $stillConfirmed = $this->links->findConfirmedStoreIds($canonicalId) !== [];
        $scope = $stillConfirmed ? RuleScope::StoreSpecific : RuleScope::Unresolved;
        $status = $stillConfirmed ? ClassificationStatus::ManuallyMatched : ClassificationStatus::Pending;

        $this->catalog->updateClassification($canonicalId, $scope->value, $status->value, 'admin rejected store match', null, $adminId, $now);
        $this->catalog->setGloballyAvailable($canonicalId, true, $now);
        $this->events->record($canonicalId, 'store_match_rejected', $current['classification_status'], $status->value, null, ['store_source_id' => $storeSourceId], $adminId, $now);
        $this->reconciler->reconcile($canonicalId, $now);
    }

    /**
     * Enables or disables a rule's global retrieval availability WITHOUT changing its classification/scope. This
     * is the explicit toggle behind the "globally available" flag; disabling retires the global projection.
     */
    public function setGloballyAvailable(int $canonicalId, bool $available, int $adminId): void
    {
        $current = $this->require($canonicalId);
        $now = $this->clock->now();

        $this->catalog->setGloballyAvailable($canonicalId, $available, $now);
        $event = $available ? 'global_availability_enabled' : 'global_availability_disabled';
        $this->events->record($canonicalId, $event, $current['classification_status'], $current['classification_status'], null, [], $adminId, $now);
        $this->reconciler->reconcile($canonicalId, $now);
    }

    /**
     * Re-run materialization for a rule from its current confirmed state (e.g. after a store KB is provisioned).
     */
    public function reprocess(int $canonicalId, int $adminId): void
    {
        $current = $this->require($canonicalId);
        $now = $this->clock->now();

        $this->events->record($canonicalId, 'document_requeued', $current['classification_status'], $current['classification_status'], null, [], $adminId, $now);
        $this->reconciler->reconcile($canonicalId, $now);
    }

    private function transition(int $canonicalId, RuleScope $scope, ClassificationStatus $status, string $eventType, int $adminId): void
    {
        $current = $this->require($canonicalId);
        $now = $this->clock->now();

        $this->catalog->updateClassification($canonicalId, $scope->value, $status->value, 'admin: ' . $eventType, null, $adminId, $now);
        // Ignoring turns off global availability (retiring both projections); any other decision keeps the rule
        // globally available (mark common / mark unresolved / restore all preserve the global projection).
        $this->catalog->setGloballyAvailable($canonicalId, $status !== ClassificationStatus::Ignored, $now);
        $this->events->record($canonicalId, $eventType, $current['classification_status'], $status->value, null, [], $adminId, $now);
        $this->reconciler->reconcile($canonicalId, $now);
    }

    /**
     * @return array{title: string, content: string, canonical_hash: string, scope_type: string, classification_status: string, is_active: bool, is_globally_available: bool}
     */
    private function require(int $canonicalId): array
    {
        $current = $this->catalog->findClassification($canonicalId);
        if ($current === null) {
            throw new NotFoundException('rule_not_found', 'Rule not found.');
        }

        return $current;
    }
}
