<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\Shared\Order58\DemoOrderUrl;

/**
 * Reads the mirrored original order — the left-hand side of every comparison.
 *
 * ## Both ids, always
 *
 * Every method takes the store as well as the order. `order58_orders` is keyed
 * `UNIQUE (account_id, source_order_id)` precisely because order ids are not promised to be globally
 * unique, and a lookup by order id alone would let a URL render one merchant's order on another
 * merchant's page. The store comes from the route and bounds every query.
 *
 * ## A missing source order is a normal answer
 *
 * The mirror is filled by syncing a store and a date range that somebody asked for. A demo order can
 * legitimately exist for an order nobody has synced. `null` means exactly that, and the comparison page
 * renders the demo side in full beside it.
 */
interface SourceOrderReaderInterface
{
    /** The mirrored original order, normalised, or null when the mirror does not hold it. */
    public function find(int $storeSourceId, int $sourceOrderId): ?NormalizedOrder;

    /**
     * The Order58 demo link for one order, or a status saying why there is none.
     *
     * Here rather than in the web action because it needs the same two tables and the same account
     * scoping, and because the URL must only ever be built by {@see DemoOrderUrl}, which validates the
     * host against an allow-list. A link assembled anywhere else would be an open redirect carrying a
     * customer's phone number.
     */
    public function demoLinkFor(int $storeSourceId, int $sourceOrderId): DemoOrderUrl;
}
