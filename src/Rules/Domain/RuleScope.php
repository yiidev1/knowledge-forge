<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * A canonical rule's resolved scope. `unresolved` is the safe default: a rule is never treated as common or
 * store-specific until it is deterministically matched or an admin confirms it.
 */
enum RuleScope: string
{
    case Common = 'common';
    case StoreSpecific = 'store_specific';
    case Unresolved = 'unresolved';
}
