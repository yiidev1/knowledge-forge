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
     * `$onlyActorRealm`/`$onlyActorId` narrow the count to demo orders **reliably attributed to one
     * practitioner**. That filter is an authorization boundary, not a convenience: an agent is shown
     * what they produced, and the unfiltered count is a different question that the management
     * surfaces ask.
     *
     * Narrowed, the count deliberately excludes **Unmatched** demo orders — ones whose creating
     * attempt could not be established. They belong to nobody, so they belong to nobody's count;
     * offering one to whoever happens to be looking is the invented attribution the importer
     * refuses to make. The realm travels with the id because the three realms number their users
     * separately: `admin_users.id = 7` and Order58's `admin_id = 7` are different people.
     *
     * @param list<string> $orderIds the raw `audio_conversations.order_id` values, as typed
     * @param ?string      $onlyActorRealm an `order_testing_attempts.initiated_by_type` value
     *
     * @return array<string, int> keyed by the order id given, for every id asked about
     */
    public function countsFor(
        int $storeSourceId,
        array $orderIds,
        ?string $onlyActorRealm = null,
        ?int $onlyActorId = null,
    ): array;
}
