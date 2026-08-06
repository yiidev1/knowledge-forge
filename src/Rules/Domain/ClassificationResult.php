<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * The deterministic outcome of classifying one canonical rule: its resolved scope + workflow status, the store
 * link candidates (if any), any detected-but-unmatched store text, and a short human-readable reason.
 *
 * Note: `status` is never `manually_matched` or `ignored` — those are admin-only decisions. It CAN be
 * `confirmed_common` when no store candidate is found (auto-confirmed common, so the rule is searchable
 * everywhere without a manual step).
 */
final readonly class ClassificationResult
{
    /**
     * @param list<StoreLinkCandidate> $candidates
     */
    public function __construct(
        public RuleScope $scope,
        public ClassificationStatus $status,
        public array $candidates,
        public ?string $detectedStoreText,
        public string $reason,
    ) {}
}
