<?php

declare(strict_types=1);

namespace App\Order58\Domain\Items;

use DateTimeImmutable;

/**
 * Stores and reads one order's original items.
 *
 * ## Replacement, not merge
 *
 * The provider returns a complete set per order, so the set **is** the truth. {@see replace()} makes
 * the stored rows equal the response in one transaction: anything missing from the response is gone
 * because the provider says it is gone, not because a merge failed to notice it.
 *
 * That is only safe because the caller has already proved the response complete — see
 * {@see \App\Order58\Client\Items\HttpOrderItemClient}, which refuses anything partial. A repository
 * that replaced on a half-read body would delete real lines.
 */
interface OrderItemRepositoryInterface
{
    /**
     * Make this order's stored items equal the given set, atomically.
     *
     * Ownership is re-checked **inside** the transaction against `items_lease_owner`: if the lease has
     * moved on, nothing is deleted and nothing is inserted. Checking only afterwards would let a stale
     * worker destroy a newer snapshot and then merely fail to update the status.
     *
     * @param list<Order58OrderItem> $items may be empty — that is an authoritative empty order
     *
     * @return bool false when the lease was no longer ours and the transaction was abandoned
     */
    public function replace(
        int $storeAccountId,
        int $sourceOrderId,
        array $items,
        string $leaseOwner,
        DateTimeImmutable $now,
    ): bool;

    /**
     * One order's items, in provider order.
     *
     * @return list<Order58OrderItem>
     */
    public function forOrder(int $storeAccountId, int $sourceOrderId): array;
}
