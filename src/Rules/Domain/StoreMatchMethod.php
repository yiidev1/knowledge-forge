<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * How a rule→store link was determined, in descending authority. `source_store_id` (a future authoritative id)
 * outranks every name-based method; `fuzzy_suggestion` is the weakest and never auto-confirms.
 */
enum StoreMatchMethod: string
{
    case SourceStoreId = 'source_store_id';
    case Domain = 'domain';
    case TitleExactAlias = 'title_exact_alias';
    case DescriptionExactAlias = 'description_exact_alias';
    case Manual = 'manual';
    case FuzzySuggestion = 'fuzzy_suggestion';
}
