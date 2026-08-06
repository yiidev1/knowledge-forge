<?php

declare(strict_types=1);

namespace App\Rules\Domain;

use function ceil;
use function max;
use function min;

/**
 * A page of readiness rows plus the total row count for the active search + filter.
 */
final readonly class RuleReadinessResult
{
    /**
     * @param list<RuleReadinessItem> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function currentPage(): int
    {
        return min(max(1, $this->page), $this->pageCount());
    }
}
