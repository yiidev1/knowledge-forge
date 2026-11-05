<?php

declare(strict_types=1);

namespace App\Practice\Domain;

/**
 * The stores a topic may be restricted to.
 *
 * The same stores the Order Testing picker lists, from the same table and keyed by the same id — a
 * second notion of "which stores exist" is how a dropdown ends up offering something the rest of the
 * application cannot resolve.
 *
 * "Offered" is narrower than "exists": the configured test and demo accounts
 * ({@see ExcludedStoreIds}) exist, and are still named in the listing, but are never offered for a new
 * store choice. The dropdown and the server ask the same question, through {@see all()} and
 * {@see isOffered()}, so a hand-made POST cannot choose what the form does not offer.
 */
interface StoreOptionsInterface
{
    /**
     * Every store a topic may be saved against, by name.
     *
     * @param int|null $keep the store the topic being edited already belongs to. It is listed even when
     *                       it is no longer offered, so the dropdown can show the topic as it is and a
     *                       rename cannot silently move it to Global.
     *
     * @return list<StoreOption>
     */
    public function all(?int $keep = null): array;

    /** Whether this id names a store that is really there. */
    public function exists(int $storeSourceId): bool;

    /** Whether this id may be chosen for a topic now: it exists and is not an excluded account. */
    public function isOffered(int $storeSourceId): bool;

    /**
     * Names for the ids given, for the listing.
     *
     * @param list<int> $storeSourceIds
     * @return array<int, string> id => name, missing ids simply absent
     */
    public function namesFor(array $storeSourceIds): array;
}
