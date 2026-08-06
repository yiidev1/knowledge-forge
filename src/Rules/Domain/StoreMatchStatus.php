<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * The review state of a rule→store link. Only a `confirmed` link may become a searchable store-specific
 * document (Phase 3). A `fuzzy_suggestion` stays `suggested` until an admin confirms it; a `rejected` link is
 * kept for audit and does not silently reappear.
 */
enum StoreMatchStatus: string
{
    case Suggested = 'suggested';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
