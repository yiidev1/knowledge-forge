<?php

declare(strict_types=1);

namespace App\Rules\Application;

use App\Order58\Contract\Dto\Order58RuleRecord;
use App\Rules\Contract\RuleCatalogRepositoryInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use DateTimeImmutable;

/**
 * Links a raw Order58 rule record to exactly one canonical rule, deduplicating by content identity.
 *
 * Exact-duplicate identity is `canonical_hash` = SHA-256(normalized title + "\0" + normalized description): two
 * sources collapse only when both match, so same-title/different-body rules stay separate. Every raw row is
 * preserved and audit-linked (primary or exact_duplicate); none is discarded.
 *
 * The whole link is one transaction and idempotent: re-running with unchanged content is a no-op. When a
 * source's content changes upstream (its `canonical_hash` changes) the SAME source row keeps its id but its
 * link is moved to the new canonical (create-or-reuse), and `is_active` is recomputed for BOTH the old and new
 * canonical — the old one becomes inactive once it has no remaining active sources. This never fails on the
 * `order58_rule_record_id` UNIQUE, because it updates the existing link rather than inserting a second one.
 */
final readonly class RuleCatalogService
{
    public function __construct(
        private RuleCatalogRepositoryInterface $catalog,
        private RuleHasher $hasher,
        private TransactionRunnerInterface $transaction,
    ) {}

    public function linkSource(int $order58RuleRecordId, Order58RuleRecord $record, DateTimeImmutable $now): RuleCatalogOutcome
    {
        $identity = $this->hasher->identify($record->title, $record->description);

        return $this->transaction->run(function () use ($order58RuleRecordId, $record, $identity, $now): RuleCatalogOutcome {
            $canonicalId = $this->catalog->findIdByCanonicalHash($identity->canonicalHash);
            $created = false;
            if ($canonicalId === null) {
                $canonicalId = $this->catalog->insertCanonical(
                    $identity->canonicalHash,
                    $identity->descriptionHash,
                    $record->title,
                    $identity->content,
                    $now,
                );
                $created = true;
            }

            $existing = $this->catalog->findSourceLink($order58RuleRecordId);

            if ($existing === null) {
                $relation = $this->catalog->countSourcesForCanonical($canonicalId) === 0 ? 'primary' : 'exact_duplicate';
                $this->catalog->insertSourceLink($canonicalId, $order58RuleRecordId, $relation, $now);
                $this->catalog->recomputeActive($canonicalId, $now);

                return $created ? RuleCatalogOutcome::CanonicalCreated : RuleCatalogOutcome::ExactDuplicateLinked;
            }

            if ($existing['canonical_id'] === $canonicalId) {
                // Already linked to the correct canonical — idempotent no-op (keep the active flag fresh).
                $this->catalog->recomputeActive($canonicalId, $now);

                return RuleCatalogOutcome::Unchanged;
            }

            // Upstream content changed: move this source to the new canonical and recompute both.
            $oldCanonicalId = $existing['canonical_id'];
            $relation = $this->catalog->countSourcesForCanonical($canonicalId) === 0 ? 'primary' : 'exact_duplicate';
            $this->catalog->relinkSource($order58RuleRecordId, $canonicalId, $relation, $now);
            $this->catalog->recomputeActive($canonicalId, $now);
            $this->catalog->recomputeActive($oldCanonicalId, $now);

            return RuleCatalogOutcome::Relinked;
        });
    }

    /**
     * Recomputes the active flag of the canonical rule a (now-deactivated) source record belongs to, if any.
     * Called after a mark-and-sweep so a canonical with no remaining active sources becomes inactive.
     */
    public function recomputeActiveForRecord(int $order58RuleRecordId, DateTimeImmutable $now): void
    {
        $canonicalId = $this->catalog->findCanonicalIdForRecord($order58RuleRecordId);
        if ($canonicalId !== null) {
            $this->catalog->recomputeActive($canonicalId, $now);
        }
    }
}
