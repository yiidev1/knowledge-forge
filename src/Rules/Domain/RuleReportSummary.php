<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * The read-only summary of the rule catalog for the admin report.
 *
 * `exactDuplicateSources` counts raw source rows linked as an exact duplicate of a canonical rule. `possible
 * DuplicateGroups` counts groups of canonical rules that share a normalized description but differ in title —
 * surfaced for review only; they are never auto-merged.
 */
final readonly class RuleReportSummary
{
    /**
     * @param array<string, int> $classificationByStatus Count of active canonical rules per classification_status.
     * @param array<string, int> $documentsByStatus       Count of rule documents per document status.
     */
    public function __construct(
        public int $sourceTotal,
        public int $sourceActive,
        public int $canonicalTotal,
        public int $canonicalActive,
        public int $exactDuplicateSources,
        public int $possibleDuplicateGroups,
        public array $classificationByStatus = [],
        public int $accountedActiveSources = 0,
        public array $documentsByStatus = [],
        public int $globallyAvailable = 0,
    ) {}

    public function classified(string $status): int
    {
        return $this->classificationByStatus[$status] ?? 0;
    }

    /**
     * Active canonical rules with no store/common decision yet (pending + suggested_common + ambiguous +
     * unmatched) — the review backlog.
     */
    public function needsReview(): int
    {
        return $this->classified('pending')
            + $this->classified('suggested_common')
            + $this->classified('ambiguous')
            + $this->classified('unmatched');
    }

    public function documents(string $status): int
    {
        return $this->documentsByStatus[$status] ?? 0;
    }

    /**
     * Every active raw source rule must be accounted for by exactly one canonical link. When these differ, the
     * report shows a reconciliation warning so drift is visible.
     */
    public function reconciles(): bool
    {
        return $this->sourceActive === $this->accountedActiveSources;
    }

    public function unaccountedActiveSources(): int
    {
        return $this->sourceActive - $this->accountedActiveSources;
    }
}
