<?php

declare(strict_types=1);

namespace App\Order58\Domain\Items;

/**
 * What is known about one order's item synchronisation at the moment it was claimed.
 *
 * `responseHash` is the fingerprint of the last response that was stored. Comparing a fresh response
 * against it is what makes a repeat sync cost no item writes — while still letting the caller refresh
 * the freshness metadata, which must happen even when nothing changed.
 */
final readonly class ItemSyncState
{
    public function __construct(
        public int $accountId,
        public int $sourceOrderId,
        public string $leaseOwner,
        public ?string $responseHash,
        public int $attempts,
        /** True when a previous sync succeeded; the discriminator for "stale snapshot kept". */
        public bool $hasSnapshot,
    ) {}
}
