<?php

declare(strict_types=1);

namespace App\Order58\Domain\Orders;

/**
 * The read-only list's filters, already validated.
 *
 * `perPage` is capped rather than taken from the request, so a crafted `?per_page=100000` cannot ask the
 * database for the whole table and the browser for all of it.
 */
final readonly class OrderListQuery
{
    public const PER_PAGE = 50;
    public const MAX_PAGE = 2000;

    public function __construct(
        public ?int $accountId = null,
        public ?int $sourceOrderId = null,
        public ?string $status = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public int $page = 1,
    ) {}

    public function offset(): int
    {
        return (max(1, min($this->page, self::MAX_PAGE)) - 1) * self::PER_PAGE;
    }
}
