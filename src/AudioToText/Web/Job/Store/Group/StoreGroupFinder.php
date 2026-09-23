<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Store\Group;

use App\AudioToText\Domain\AudioStoreLookupInterface;
use App\AudioToText\Domain\GroupKey;
use App\AudioToText\Domain\StoreOrderGroup;
use App\AudioToText\Domain\StoreOrderGroupRepositoryInterface;

/**
 * **THE SECURITY BOUNDARY for every group endpoint.**
 *
 * The store page's modals are addressed by a store id and a group key, and both arrive from a browser.
 * Neither is trusted: this resolves the store, then asks the repository for that group **within that
 * store**, and the store predicate is part of the SQL rather than a check applied to the answer. A key
 * belonging to another store therefore matches no row and comes back null.
 *
 * Null is the only failure. "No such group", "that key is not one this application issues" and "that
 * group belongs to somebody else" are deliberately indistinguishable from outside, exactly as
 * {@see \App\AudioToText\Web\Job\JobPageGuard} makes them for a job: an id that answers differently
 * when it exists is an id that can be used to find out what exists.
 *
 * Every endpoint under this namespace goes through here. Adding one that resolves a group any other
 * way would be adding a second answer to the only question that matters.
 */
final readonly class StoreGroupFinder
{
    public function __construct(
        private AudioStoreLookupInterface $stores,
        private StoreOrderGroupRepositoryInterface $groups,
    ) {}

    public function find(int $sourceId, string $rawKey): ?StoreOrderGroup
    {
        // Shape first: a key this application could never have issued is refused before it reaches a
        // query. Not a permission check — the store predicate below is — just a closed door.
        $key = GroupKey::fromInput($rawKey);

        if ($key === null) {
            return null;
        }

        $store = $this->stores->findBySourceId($sourceId);

        if ($store === null) {
            return null;
        }

        return $this->groups->groupFor($store->sourceId, $key);
    }
}
