<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * One raw Order58 source rule folded into a canonical rule (the audit link).
 */
final readonly class RuleSourceRow
{
    public function __construct(
        public int $sourceId,
        public string $title,
        public string $relationType,
        public bool $isActive,
    ) {}
}
