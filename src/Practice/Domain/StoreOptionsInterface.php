<?php

declare(strict_types=1);

namespace App\Practice\Domain;

/**
 * The stores a topic may be restricted to.
 *
 * The same stores the Order Testing picker lists, from the same table and keyed by the same id — a
 * second notion of "which stores exist" is how a dropdown ends up offering something the rest of the
 * application cannot resolve.
 */
interface StoreOptionsInterface
{
    /** @return list<StoreOption> every selectable store, by name */
    public function all(): array;

    /** Whether this id names a store that is really there. Used before a topic is saved against it. */
    public function exists(int $storeSourceId): bool;

    /**
     * Names for the ids given, for the listing.
     *
     * @param list<int> $storeSourceIds
     * @return array<int, string> id => name, missing ids simply absent
     */
    public function namesFor(array $storeSourceIds): array;
}
