<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * How many demo orders each order on a store page has produced.
 *
 * Declared here, in Audio-to-Text's own Domain, because the data lives in a table another module owns
 * and `ModuleIsolationTest` forbids this module from naming that module. The adapter reads the table; it
 * never imports the classes — exactly the arrangement {@see DemoOrderLinkReaderInterface} already uses
 * for `order58_orders`.
 *
 * Only a **count** crosses the boundary. Everything else about a demo order — its contents, its
 * attribution, its comparison against the original — belongs to the Order Testing module and is reached
 * by following a link to its own pages, not by reading its rows from here.
 */
interface DemoOrderCountReaderInterface
{
    /**
     * One batch lookup for a whole page of rows.
     *
     * **Scoped to one store.** `$storeSourceId` bounds the query, so an order id belonging to another
     * merchant cannot be counted onto this page.
     *
     * @param list<string> $orderIds the raw `audio_conversations.order_id` values, as typed
     *
     * @return array<string, int> keyed by the order id given, for every id asked about
     */
    public function countsFor(int $storeSourceId, array $orderIds): array;
}
