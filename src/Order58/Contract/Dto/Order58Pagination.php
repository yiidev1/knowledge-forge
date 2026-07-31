<?php

declare(strict_types=1);

namespace App\Order58\Contract\Dto;

/**
 * The `pagination` block of an Order58 list envelope.
 *
 * {@see hasMore()} is the loop condition for a full scan — the sync walks pages until the API says there
 * are no more, rather than assuming a single page is complete.
 */
final readonly class Order58Pagination
{
    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
        public int $totalPages,
    ) {}

    public function hasMore(): bool
    {
        return $this->page < $this->totalPages;
    }
}
