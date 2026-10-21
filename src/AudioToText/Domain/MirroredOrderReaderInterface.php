<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * Resolves the mirrored Order58 record behind each order id shown on one store's page.
 *
 * Declared here, in Audio-to-Text's own Domain, because the data lives in a table another module owns
 * and `ModuleIsolationTest` forbids this module from naming that module. The adapter reads the table; it
 * never imports the classes — exactly the arrangement {@see DemoOrderLinkReaderInterface} already uses
 * for the same table.
 */
interface MirroredOrderReaderInterface
{
    /**
     * One batch lookup for a whole page of rows.
     *
     * Deliberately plural. A method that answered for one order would be called once per row, and a
     * page holds twenty — the N+1 this exists to avoid.
     *
     * **Scoped to one account.** `$accountId` is the store whose page is being rendered, and it bounds
     * the query. `order58_orders` is unique on `(account_id, source_order_id)` and *not* on the order id
     * alone, so without this bound one merchant's order number could show another merchant's seq.
     *
     * @param list<string> $orderIds the raw `audio_conversations.order_id` values, as typed
     *
     * @return array<string, MirroredOrder> keyed by the order id given, containing an entry **only**
     *                                      for the ids that really name an order under this account.
     *                                      A missing key means "no such order here", which is a
     *                                      different answer from "an order with no seq".
     */
    public function forOrders(int $accountId, array $orderIds): array;
}
