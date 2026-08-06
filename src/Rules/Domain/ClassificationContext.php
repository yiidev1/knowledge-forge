<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * The store data the matcher needs, loaded once per classification pass and reused across many rules.
 */
final readonly class ClassificationContext
{
    /**
     * @param list<int>           $knownStoreIds
     * @param list<ApprovedAlias> $aliases
     */
    public function __construct(
        public array $knownStoreIds,
        public array $aliases,
    ) {}
}
