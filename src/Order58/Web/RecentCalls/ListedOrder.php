<?php

declare(strict_types=1);

namespace App\Order58\Web\RecentCalls;

use App\Order58\Domain\Orders\Order58Order;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use App\Order58\Domain\Orders\OrderListQuery;
use DateTimeImmutable;
use LogicException;

/**
 * The one order {@see ReceiptAction} has already proved belongs to the store, presented to the existing
 * receipt action as the answer to its ownership check.
 *
 * That action confirms an order is the store's by asking the order mirror. Recent Calls proves it a
 * different way — the order was in the provider's own list for that store — so this stands in for the
 * mirror and knows exactly that one pair. Every other question is refused: it reads nothing and writes
 * nothing, and it is constructed only after {@see RecentCallsReceipts::allows()} has said yes.
 */
final readonly class ListedOrder implements Order58OrderRepositoryInterface
{
    public function __construct(
        private int $accountId,
        private int $orderId,
    ) {}

    public function hashesFor(int $accountId, array $sourceOrderIds): array
    {
        $known = [];

        foreach ($sourceOrderIds as $id) {
            if ($accountId === $this->accountId && $id === $this->orderId) {
                $known[$id] = '';
            }
        }

        return $known;
    }

    public function upsert(Order58Order $order, DateTimeImmutable $now): bool
    {
        throw new LogicException('A listed order is read-only.');
    }

    public function page(OrderListQuery $query): array
    {
        throw new LogicException('A listed order is not a list of orders.');
    }
}
