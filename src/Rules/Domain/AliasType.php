<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * The provenance of a store alias. Seeded aliases (`official_name`, `company_name`, `domain`) come from the
 * store mirror; `manual` is admin-added; `discovered`/`generated` are reserved for later automated suggestions.
 */
enum AliasType: string
{
    case OfficialName = 'official_name';
    case CompanyName = 'company_name';
    case Domain = 'domain';
    case Generated = 'generated';
    case Discovered = 'discovered';
    case Manual = 'manual';
}
