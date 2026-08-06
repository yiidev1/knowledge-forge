<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * The classification workflow state of a canonical rule — a reporting/scope concern, kept SEPARATE from
 * retrieval availability (see `rule_catalog_rules.is_globally_available`).
 *
 * A rule with no store candidate is auto-confirmed as `confirmed_common` by the sync/classification system (so
 * it is globally searchable without a manual step); an admin can also set it. `suggested_common` remains only a
 * legacy heuristic hint. Classification never blocks retrieval on its own: every active rule is globally
 * available by default regardless of status.
 */
enum ClassificationStatus: string
{
    case Pending = 'pending';
    case AutoMatched = 'auto_matched';
    case ManuallyMatched = 'manually_matched';
    case SuggestedCommon = 'suggested_common';
    case ConfirmedCommon = 'confirmed_common';
    case Ambiguous = 'ambiguous';
    case Unmatched = 'unmatched';
    case Ignored = 'ignored';

    /**
     * Statuses an automated sync pass is allowed to set. Admin-only decisions (confirmed_common, manually_matched,
     * ignored) are deliberately excluded so a resync can never overwrite a human decision.
     */
    public function isAutomatable(): bool
    {
        return match ($this) {
            self::Pending, self::AutoMatched, self::SuggestedCommon, self::Ambiguous, self::Unmatched => true,
            self::ManuallyMatched, self::ConfirmedCommon, self::Ignored => false,
        };
    }
}
