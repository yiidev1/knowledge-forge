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
 */
interface StoreOrderGroupRepositoryInterface
{
    /**
     * One page of this store's orders, newest activity first.
     *
     * @return list<StoreOrderGroup>
     */
    public function pageFor(int $storeSourceId, int $limit, int $offset = 0): array;

    /** How many orders this store has, for the pager. */
    public function countFor(int $storeSourceId): int;

    /**
     * One group of this store's, or null when the key names nothing this store owns.
     *
     * Null covers both "no such group" and "that group belongs to another store", deliberately: the
     * modal endpoints answer 404 either way, so a key cannot be used to discover what exists elsewhere.
     */
    public function groupFor(int $storeSourceId, GroupKey $key): ?StoreOrderGroup;
}
