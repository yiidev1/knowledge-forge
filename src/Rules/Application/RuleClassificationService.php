<?php

declare(strict_types=1);

namespace App\Rules\Application;

use App\Rules\Contract\RuleCatalogRepositoryInterface;
use App\Rules\Contract\RuleClassificationEventRepositoryInterface;
use App\Rules\Contract\RuleStoreLinkRepositoryInterface;
use App\Rules\Domain\ClassificationContext;
use App\Rules\Domain\ClassificationStatus;
use DateTimeImmutable;

/**
 * Applies the deterministic {@see RuleStoreMatcher} to one canonical rule and persists the result idempotently.
 *
 * Safety properties:
 *  - An automated pass (adminId === null) NEVER overwrites an admin decision — a canonical whose status is not
 *    automatable (manually_matched / confirmed_common / ignored) is left untouched.
 *  - Link upserts protect admin-confirmed/-rejected links (see {@see RuleStoreLinkRepositoryInterface}).
 *  - A classification event is recorded ONLY when the scope or status actually changes, so a repeated sync of
 *    unchanged data produces no new rows.
 *  - The matcher auto-confirms a no-store rule as `confirmed_common` (reason `auto_confirmed_common_*`); once
 *    set it is no longer automatable, so a later sync leaves it (and any admin decision) untouched.
 */
final readonly class RuleClassificationService
{
    public function __construct(
        private RuleCatalogRepositoryInterface $catalog,
        private RuleStoreLinkRepositoryInterface $links,
        private RuleClassificationEventRepositoryInterface $events,
        private RuleStoreMatcher $matcher,
    ) {}

    public function classify(int $canonicalId, ClassificationContext $context, DateTimeImmutable $now, ?int $adminId = null): void
    {
        $current = $this->catalog->findClassification($canonicalId);
        if ($current === null) {
            return;
        }

        $currentStatus = ClassificationStatus::tryFrom($current['classification_status']);
        if ($adminId === null && $currentStatus !== null && !$currentStatus->isAutomatable()) {
            // Never re-classify an admin decision on an automated pass.
            return;
        }

        $sourceStoreId = $this->catalog->findSourceStoreIdForCanonical($canonicalId);
        $result = $this->matcher->match(
            $sourceStoreId,
            $context->knownStoreIds,
            $current['title'],
            $current['content'],
            $context->aliases,
        );

        foreach ($result->candidates as $candidate) {
            $this->links->upsertSystemLink(
                $canonicalId,
                $candidate->storeSourceId,
                $candidate->status,
                $candidate->method,
                $candidate->matchedText,
                $candidate->confidence,
                $now,
            );
        }

        $changed = $current['scope_type'] !== $result->scope->value
            || $current['classification_status'] !== $result->status->value;

        if (!$changed) {
            return;
        }

        $this->catalog->updateClassification(
            $canonicalId,
            $result->scope->value,
            $result->status->value,
            $result->reason,
            $result->detectedStoreText,
            null,
            $now,
        );
        $this->events->record(
            $canonicalId,
            'classified',
            $current['classification_status'],
            $result->status->value,
            $result->reason,
            ['scope' => $result->scope->value],
            null,
            $now,
        );
    }
}
