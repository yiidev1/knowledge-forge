<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use App\Shared\Order58\DemoOrderUrl;

/**
 * Resolves demo links for the order ids shown on one store's page.
 *
 * Declared here, in Audio-to-Text's own Domain, because the data lives in tables another module owns and
 * `ModuleIsolationTest` forbids this module from naming that module. The adapter reads the tables; it
 * never imports the classes — exactly the arrangement {@see AudioStoreLookupInterface} already uses for
 * `order58_stores`.
 */
interface DemoOrderLinkReaderInterface
{
    /**
     * One batch lookup for a whole page of rows.
     *
     * Deliberately plural. A method that answered for one order would be called once per row, and a
     * page holds twenty — the N+1 this exists to avoid.
     *
     * **Scoped to one account.** `$accountId` is the store whose page is being rendered, and it bounds
     * the query: an order id belonging to another store cannot be matched here, so a demo link can never
     * point at a different merchant's order.
     *
     * @param list<string> $orderIds the raw `audio_conversations.order_id` values, as typed
     *
     * @return array<string, DemoOrderUrl> keyed by the order id given, for every id asked about
     */
    public function linksFor(int $accountId, array $orderIds): array;
}
