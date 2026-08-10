<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * The count cards on the readiness page. Every figure uses the same operational-status derivation as the table
 * filters, so a card count always equals the rows returned by the matching filter. {@see total()} is the count of
 * synced Order58 source rules in the view (not necessarily materialized documents).
 */
final readonly class RuleReadinessSummary
{
    public function __construct(
        public int $ready = 0,
        public int $queued = 0,
        public int $processing = 0,
        public int $indexing = 0,
        public int $failed = 0,
        public int $disabled = 0,
        public int $inactive = 0,
        public int $notMaterialized = 0,
    ) {}

    public function pending(): int
    {
        return $this->queued + $this->processing + $this->indexing;
    }

    /** Disabled + Inactive — the combined "Disabled / Inactive" card and filter. */
    public function disabledOrInactive(): int
    {
        return $this->disabled + $this->inactive;
    }

    /** Synced Order58 source rules matching the current search (all operational statuses). */
    public function total(): int
    {
        return $this->ready
            + $this->pending()
            + $this->failed
            + $this->disabled
            + $this->inactive
            + $this->notMaterialized;
    }

    /**
     * @param array<string, int> $counts operational status value => count
     */
    public static function fromCounts(array $counts): self
    {
        return new self(
            ready: $counts[RuleReadinessStatus::Ready->value] ?? 0,
            queued: $counts[RuleReadinessStatus::Queued->value] ?? 0,
            processing: $counts[RuleReadinessStatus::Processing->value] ?? 0,
            indexing: $counts[RuleReadinessStatus::Indexing->value] ?? 0,
            failed: $counts[RuleReadinessStatus::Failed->value] ?? 0,
            disabled: $counts[RuleReadinessStatus::Disabled->value] ?? 0,
            inactive: $counts[RuleReadinessStatus::Inactive->value] ?? 0,
            notMaterialized: $counts[RuleReadinessStatus::NotMaterialized->value] ?? 0,
        );
    }
}
