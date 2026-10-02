<?php

declare(strict_types=1);

namespace App\Order58\Domain\Orders;

use DateTimeImmutable;

/**
 * Persistence for mirrored orders.
 *
 * Note what is absent: there is no delete and no deactivate. An order missing from a date-range response
 * means it was not in that range, not that it stopped existing, and a mirror that cannot tell the
 * difference must not guess.
 */
interface Order58OrderRepositoryInterface
{
    /**
     * The stored content hashes for the given orders of one account, keyed by source order id.
     *
     * One query for a whole chunk rather than one per order: the alternative is the N+1 that turns a
     * 200-order sync into 200 round trips inside a request that has seconds to spare.
     *
     * @param list<int> $sourceOrderIds
     * @return array<int, string>
     */
    public function hashesFor(int $accountId, array $sourceOrderIds): array;

    /**
     * Inserts or updates one order. Called only for records whose hash actually moved.
     *
     * @return bool true when the row was created, false when an existing row was updated
     */
    public function upsert(Order58Order $order, DateTimeImmutable $now): bool;

    /**
     * One page of stored orders, newest first by the provider's own clock.
     *
     * @return array{rows: list<array<array-key, mixed>>, total: int}
     */
    public function page(OrderListQuery $query): array;
}
