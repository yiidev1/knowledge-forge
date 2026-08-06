<?php

declare(strict_types=1);

namespace App\Rules\Domain;

use function in_array;

/**
 * Everything the rule review page needs about one canonical rule: its full content + classification, the store
 * it is confirmed to (if any) and the store an auto-match suggested (if any), every raw source row that folds
 * into it, its searchable document(s), and its classification history.
 */
final readonly class RuleDetail
{
    /**
     * @param list<RuleSourceRow>   $sources
     * @param list<RuleDocumentRow> $documents
     * @param list<RuleEventRow>    $history
     */
    public function __construct(
        public int $canonicalId,
        public string $title,
        public string $content,
        public string $scopeType,
        public string $classificationStatus,
        public ?string $detectedStoreText,
        public bool $globallyAvailable,
        public ?int $matchedStoreId,
        public ?string $matchedStoreName,
        public ?int $suggestedStoreId,
        public ?string $suggestedStoreName,
        public array $sources,
        public array $documents,
        public array $history,
    ) {}

    /** Awaiting a store/common decision (no confirmed classification yet). */
    public function needsReview(): bool
    {
        return in_array($this->classificationStatus, RuleReportFilter::NEEDS_REVIEW_STATUSES, true);
    }

    public function isAutoMatched(): bool
    {
        return $this->classificationStatus === 'auto_matched';
    }

    public function isManuallyMatched(): bool
    {
        return $this->classificationStatus === 'manually_matched';
    }

    public function isConfirmedCommon(): bool
    {
        return $this->classificationStatus === 'confirmed_common';
    }

    public function isSearchable(): bool
    {
        foreach ($this->documents as $document) {
            if ($document->status === 'ready') {
                return true;
            }
        }

        return false;
    }

    /** Global availability is the explicit flag (separate from whether the global document is ready yet). */
    public function isGloballyAvailable(): bool
    {
        return $this->globallyAvailable;
    }

    /** @return list<RuleDocumentRow> The store-KB projections (`order58_rule_store`). */
    public function storeDocuments(): array
    {
        return $this->documentsOfType(['order58_rule_store']);
    }

    /** @return list<RuleDocumentRow> The hidden global projection(s) (`order58_rule_global`, or legacy common). */
    public function globalDocuments(): array
    {
        return $this->documentsOfType(['order58_rule_global', 'order58_rule_common']);
    }

    /**
     * @param list<string> $types
     * @return list<RuleDocumentRow>
     */
    private function documentsOfType(array $types): array
    {
        $out = [];
        foreach ($this->documents as $document) {
            if (in_array($document->sourceType, $types, true)) {
                $out[] = $document;
            }
        }

        return $out;
    }
}
