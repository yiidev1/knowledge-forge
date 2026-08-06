<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * A page request for the detailed rules list: a free-text title search, a filter, and pagination.
 */
final readonly class RuleReportQuery
{
    public function __construct(
        public string $search,
        public RuleReportFilter $filter,
        public int $page,
        public int $perPage,
    ) {}

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
