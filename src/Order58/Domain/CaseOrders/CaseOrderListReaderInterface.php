<?php

declare(strict_types=1);

namespace App\Order58\Domain\CaseOrders;

/**
 * The admin Case Orders listing.
 *
 * `page()` and `count()` take the same query on purpose: a count taken under different narrowing than
 * the page reports rows the reader cannot reach, and the pager then offers pages that come back empty.
 */
interface CaseOrderListReaderInterface
{
    /** @return list<CaseOrderListItem> */
    public function page(CaseOrderListQuery $query, int $limit, int $offset): array;

    public function count(CaseOrderListQuery $query): int;

    /** @return array<int, string> stores that actually have orders, for the filter */
    public function stores(): array;
}
