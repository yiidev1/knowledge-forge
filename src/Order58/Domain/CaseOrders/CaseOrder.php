<?php

declare(strict_types=1);

namespace App\Order58\Domain\CaseOrders;

use App\Order58\Domain\Items\Order58OrderItem;
use App\Order58\Domain\Orders\Order58Order;

use function count;

/**
 * One original order from the Case Order endpoint, with its lines already attached.
 *
 * ## One definition of an order, not two
 *
 * The order half is an {@see Order58Order} — the same type the application already stores, built by
 * the same {@see \App\Order58\Application\Orders\OrderMapper} that already knows which fields are
 * money, which are timestamps, and which must be redacted before anything is kept.
 *
 * It would have been easy to give this endpoint its own order mapping, since it is replacing an
 * integration rather than extending one. That would be a second answer to "what is an order": a
 * second money list, a second redaction pass, a second content hash — and the two would disagree the
 * first time a column was added to one. `CaseOrderMapperReuseTest` pins the reuse so that if the
 * provider ever changes the order half, it fails there and a separate mapping is introduced on
 * purpose rather than by drift.
 *
 * ## What is genuinely new
 *
 * The lines. The endpoint this replaces needed a second request per order to get them, which is why
 * a backlog of part-imported orders could exist at all. Here they arrive inside the order, so an
 * order is never half-imported for want of another call.
 *
 * They are still {@see Order58OrderItem}s, built by the existing
 * {@see \App\Order58\Application\Items\OrderItemMapper}: the lines are the same lines, and in
 * particular their `comparison_json` has to be the one the demo side is compared against. A type of
 * this module's own would have had to be converted into that one anyway, and the conversion is where
 * a field gets dropped.
 */
final readonly class CaseOrder
{
    public function __construct(
        /** The order itself, mapped by the application's existing mapper. */
        public Order58Order $order,
        /** @var list<Order58OrderItem> its lines, in the order the provider listed them */
        public array $items,
    ) {}

    /** The store this order belongs to: the provider's `account_id`. */
    public function storeSourceId(): int
    {
        return $this->order->accountId;
    }

    /** The original order number everything else in this application keys on. */
    public function sourceOrderId(): int
    {
        return $this->order->sourceOrderId;
    }

    public function itemCount(): int
    {
        return count($this->items);
    }
}
