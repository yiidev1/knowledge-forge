<?php

declare(strict_types=1);

namespace App\Reports\Domain;

use function ceil;
use function max;
use function min;

/**
 * One page of a usage table (agents or stores) plus the total behind it.
 *
 * The total is the count across the whole filtered range, not the rows on this page — which is what lets the
 * pager say "Page 2 of 5" and what keeps the summary cards honest while a table is paged.
 *
 * @template T of AgentUsageRow|StoreUsageRow
 */
final readonly class UsageResult
{
    /**
     * @param list<T> $items
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
