<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

/**
 * The little this feature needs to know about a store: that it exists, and what to call it.
 *
 * A port rather than a call into the Order58 module, so Order Testing owns its own boundary and the
 * adapter addresses the mirrored table by name. Keeping it this narrow is deliberate — a page that
 * only prints a heading has no business being able to load a store aggregate.
 */
interface StoreReaderInterface
{
    /** The store's name, or null when no store has that source id. */
    public function nameOf(int $sourceId): ?string;
}
