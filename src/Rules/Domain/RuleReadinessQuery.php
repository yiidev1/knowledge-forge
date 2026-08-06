<?php

declare(strict_types=1);

namespace App\Rules\Domain;

use function max;

/**
 * The readiness list request: a free-text search, a top-level operational filter, pagination, and a scope flag
 * that restricts to the hidden Global/Common Rules base only (used by the hidden diagnostic page).
 */
final readonly class RuleReadinessQuery
{
    public function __construct(
        public string $search,
        public RuleReadinessFilter $filter,
        public int $page,
        public int $perPage,
        public bool $hiddenBaseOnly = false,
    ) {}

    public function offset(): int
    {
        return (max(1, $this->page) - 1) * $this->perPage;
    }
}
