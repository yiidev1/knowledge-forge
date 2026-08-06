<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * The count cards on the readiness page. Every figure uses the same operational-status derivation as the table
 * filters, so a card count always equals the rows returned by the matching filter.
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
    ) {}

    public function pending(): int
    {
        return $this->queued + $this->processing + $this->indexing;
    }

    public function total(): int
    {
        return $this->ready + $this->pending() + $this->failed + $this->disabled;
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
        );
    }
}
