<?php

declare(strict_types=1);

namespace App\Reports\Domain;

use function ceil;
use function max;
use function min;

/**
 * Paging state for one of the report's three independent tables.
 *
 * They page separately on purpose: paging the agent list must not drag the Questions &amp; Answers table with
 * it, so each carries its own number rather than sharing a single `page` parameter. This value object holds
 * the number and the per-page size; the reader turns it into `LIMIT`/`OFFSET` and reports the total back
 * through a matching result object.
 */
final readonly class ReportPage
{
    public function __construct(
        public int $number = 1,
        public int $perPage = 20,
    ) {}

    public function offset(): int
    {
        return (max(1, $this->number) - 1) * max(1, $this->perPage);
    }

    public function withNumber(int $number): self
    {
        return new self(max(1, $number), $this->perPage);
    }

    /**
     * @param int $total Total rows matching the filters, not the rows on this page.
     */
    public function pageCount(int $total): int
    {
        return max(1, (int) ceil($total / max(1, $this->perPage)));
    }

    public function clamped(int $total): int
    {
        return min(max(1, $this->number), $this->pageCount($total));
    }
}
