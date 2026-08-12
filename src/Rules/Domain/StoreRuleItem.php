<?php

declare(strict_types=1);

namespace App\Rules\Domain;

use function trim;

/**
 * One Order58 catalog rule classified as applying to a given store.
 *
 * Note what this is NOT: it is not a rule Store Chat can retrieve. Store rule projections were retired, so a
 * store's vector store holds no rule documents and {@see \App\Chat\Domain\ChatRetrievalScope::StoreKnowledge}
 * rejects them at the citation layer regardless. This row is classification metadata — "the rule catalog says
 * this rule applies to this store" — and the page that renders it says so plainly.
 */
final readonly class StoreRuleItem
{
    public function __construct(
        public int $canonicalId,
        public string $title,
        public RuleScope $scope,
        public string $classificationLabel,
        public bool $isActive,
        public ?StoreMatchStatus $matchStatus,
        public string $updatedAt,
        /** The rule's own text, so a reader can see what it actually says rather than only its title. */
        public ?string $content = null,
    ) {}

    public function hasContent(): bool
    {
        return $this->content !== null && trim($this->content) !== '';
    }

    /**
     * Whether this rule reaches the store because it is common to every store, rather than through an
     * explicit per-store link.
     */
    public function isCommon(): bool
    {
        return $this->scope === RuleScope::Common;
    }

    public function scopeLabel(): string
    {
        return $this->isCommon() ? 'Common (all stores)' : 'Store-specific';
    }

    public function matchLabel(): string
    {
        if ($this->isCommon()) {
            return 'Applies to every store';
        }

        return match ($this->matchStatus) {
            StoreMatchStatus::Confirmed => 'Confirmed for this store',
            StoreMatchStatus::Suggested => 'Suggested for this store',
            StoreMatchStatus::Rejected => 'Rejected for this store',
            null => 'Linked to this store',
        };
    }
}
