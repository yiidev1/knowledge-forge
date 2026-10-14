<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * A store's history, read as orders rather than as uploads.
 *
 * Separate from {@see AudioConversationRepositoryInterface} rather than bolted onto it: that one is the
 * conversation's own repository, used by the queue, the workers and the conversion pages, and it
 * returns whole conversations. This one answers exactly one screen's question — "what orders does this
 * store have, and what is in each" — and returns a read model shaped for it.
 *
 * ## The contract that matters
 *
 * **Paging is by group, not by conversation.** Twenty rows means twenty orders, however many uploads
 * they hold between them. Fetching twenty conversations and grouping them afterwards would put a
 * random subset of one order on page 1 and the rest on page 2, which is the specific bug this
 * interface exists to make impossible.
 *
 * **The store is part of every query, never a filter applied afterwards.** A group key arriving from a
 * browser is matched *within* the store's own rows, so a key belonging to another store resolves to
 * nothing rather than to somebody else's recording.
 *
 * **The number of queries does not depend on the number of rows.** Implementations batch; a page of
 * twenty groups costs the same as a page of one.
 *
 * **The count and the page are narrowed by the same filter, or by neither.** {@see OrderGroupFilter} is
 * passed to both or to neither; a pager counting rows the page has filtered out is the other half of the
 * bug the paging contract above exists to prevent.
 */
interface StoreOrderGroupRepositoryInterface
{
    /**
     * One page of this store's orders, newest activity first.
     *
     * @param OrderGroupFilter|null $filter which orders to count as rows; null is every order, which is
     *                                      what the audio store page and Order Testing ask for, and
     *                                      leaves the query exactly what it was before filtering existed
     *
     * @return list<StoreOrderGroup>
     */
    public function pageFor(int $storeSourceId, int $limit, int $offset = 0, ?OrderGroupFilter $filter = null): array;

    /** How many orders this store has, for the pager — under the same filter the page used. */
    public function countFor(int $storeSourceId, ?OrderGroupFilter $filter = null): int;

    /**
     * One group of this store's, or null when the key names nothing this store owns.
     *
     * Null covers both "no such group" and "that group belongs to another store", deliberately: the
     * modal endpoints answer 404 either way, so a key cannot be used to discover what exists elsewhere.
     */
    public function groupFor(int $storeSourceId, GroupKey $key): ?StoreOrderGroup;
}
