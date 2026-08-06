<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * One row of the detailed rules list: a canonical rule with its classification, matched store, duplicate group
 * size, and its two searchable-document lifecycle statuses — the store projection (`order58_rule_store`) and the
 * hidden global projection (`order58_rule_global`). Classification/scope is kept separate from global
 * availability: a store-specific rule can be globally available (as a stage-2 fallback) while still mapped to
 * its store.
 */
final readonly class RuleReportItem
{
    public function __construct(
        public int $canonicalId,
        public string $title,
        public string $scopeType,
        public string $classificationStatus,
        public ?string $detectedStoreText,
        public ?string $matchedStoreName,
        public int $duplicateGroupSize,
        public ?string $storeDocumentStatus,
        public ?string $globalDocumentStatus,
        public bool $globallyAvailable,
    ) {}

    /** Global availability is the explicit flag (a reporting concern separate from projection status). */
    public function isGloballyAvailable(): bool
    {
        return $this->globallyAvailable;
    }
}
