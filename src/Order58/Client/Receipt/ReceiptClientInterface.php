<?php

declare(strict_types=1);

namespace App\Order58\Client\Receipt;

/**
 * One order's receipt, fetched from Order58.
 *
 * An interface so the web action can be tested without a socket, and so the transport can be replaced
 * without touching the page — the same arrangement {@see \App\Order58\Client\Orders\OrderDataClientInterface}
 * already has.
 *
 * Both identifiers are **verified before they arrive here**. The account id is the store whose page the
 * administrator is on; the order id has been confirmed to exist under that account in `order58_orders`.
 * This client does not re-check them, and must never be called with values taken straight from a request.
 */
interface ReceiptClientInterface
{
    /**
     * @param int $accountId    Order58's account id — our `order58_orders.account_id`, which is the
     *                          store's `order58_stores.source_id`
     * @param int $sourceOrderId Order58's own order id — our `order58_orders.source_order_id`, which is
     *                           the `id` their orders endpoint published
     *
     * @throws ReceiptFailed when the receipt cannot be fetched, or came back as something we will not serve
     */
    public function fetch(int $accountId, int $sourceOrderId): ReceiptDelivery;
}
