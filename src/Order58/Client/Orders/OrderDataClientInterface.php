<?php

declare(strict_types=1);

namespace App\Order58\Client\Orders;

/**
 * Fetches a store's orders for a date range.
 *
 * An interface so tests can drive the sync against canned responses. **No test may reach the network**,
 * and the only way to guarantee that structurally is for the thing being faked to be an interface.
 */
interface OrderDataClientInterface
{
    /**
     * @throws OrderDataFailed on any refusal, transport failure or unusable body
     *
     * @return list<array<array-key, mixed>> the `Orders` array, decoded
     */
    public function listOrders(int $accountId, string $dateFrom, string $dateTo): array;
}
