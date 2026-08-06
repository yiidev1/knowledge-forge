<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * The filter chips on the detailed rules list. Each maps to a WHERE predicate in the reader.
 */
enum RuleReportFilter: string
{
    case All = 'all';
    case NeedsReview = 'needs_review';
    case AutoMatched = 'auto_matched';
    case ConfirmedStore = 'manually_matched';
    case ConfirmedCommon = 'confirmed_common';
    case SuggestedCommon = 'suggested_common';
    case Ambiguous = 'ambiguous';
    case Unmatched = 'unmatched';
    case Pending = 'pending';
    case Ignored = 'ignored';
    case GloballyAvailable = 'globally_available';
    case NotGloballyAvailable = 'not_globally_available';
    case NeedsStoreReview = 'needs_store_review';
    case StoreProjectionReady = 'store_projection_ready';
    case GlobalProjectionReady = 'global_projection_ready';
    case FailedIndexing = 'failed_indexing';
    case Materialized = 'materialized';

    /** classification_status values that count as "needs review" (no store/common decision yet). */
    public const NEEDS_REVIEW_STATUSES = ['pending', 'suggested_common', 'ambiguous', 'unmatched'];

    /** classification_status values that still need a store decision from an admin (globally available already). */
    public const NEEDS_STORE_REVIEW_STATUSES = ['ambiguous', 'unmatched', 'pending', 'suggested_common'];

    public static function fromRequest(?string $value): self
    {
        return $value === null ? self::All : (self::tryFrom($value) ?? self::All);
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All rules',
            self::NeedsReview => 'Needs review',
            self::AutoMatched => 'Auto-matched (awaiting confirmation)',
            self::ConfirmedStore => 'Confirmed store',
            self::ConfirmedCommon => 'Confirmed common',
            self::SuggestedCommon => 'Suggested common',
            self::Ambiguous => 'Ambiguous',
            self::Unmatched => 'Unmatched',
            self::Pending => 'Pending',
            self::Ignored => 'Ignored',
            self::GloballyAvailable => 'Globally available',
            self::NotGloballyAvailable => 'Not globally available',
            self::NeedsStoreReview => 'Needs store review',
            self::StoreProjectionReady => 'Store projection ready',
            self::GlobalProjectionReady => 'Global projection ready',
            self::FailedIndexing => 'Failed indexing',
            self::Materialized => 'Materialized (searchable)',
        };
    }
}
