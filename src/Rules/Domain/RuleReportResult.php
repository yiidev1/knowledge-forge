<?php

declare(strict_types=1);

namespace App\Rules\Domain;

use function ceil;
use function max;

/**
 * One page of the detailed rules list.
 */
final readonly class RuleReportResult
{
    /**
     * @param list<RuleReportItem> $items
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
}
