<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

/**
 * Reads the original order's line items, and whether they can be believed.
 *
 * ## Why this is not part of the source-order reader
 *
 * The original *order* comes from `order58_orders.payload_json`. Its *items* do not come from there at
 * all — that payload has 73 top-level keys and no line items at any depth, which is the whole reason a
 * second provider endpoint exists. They come from `order58_order_items`, filled by a sync somebody
 * asked for, and they carry a synchronisation state the order itself does not have.
 *
 * Keeping them apart is what lets the page say "the order is mirrored but its items have never been
 * fetched" — a state that is invisible if items are just a field on the order.
 *
 * ## Both ids, always
 *
 * Store and order both bound every query, for the same reason they bound the order lookup: order ids
 * are not promised to be globally unique, and a lookup by order alone would render one merchant's lines
 * on another merchant's page.
 *
 * ## Never the provider
 *
 * This reads local tables only. Rendering a comparison must not depend on a third party being reachable,
 * and an item fetch is an explicit action with a lock, a lease and a retry policy of its own.
 */
interface OriginalItemsReaderInterface
{
    public function read(int $storeSourceId, int $sourceOrderId): OriginalItems;
}
