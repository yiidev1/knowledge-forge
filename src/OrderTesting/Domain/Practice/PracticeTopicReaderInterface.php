<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Practice;

/**
 * The topic a source order is practised under, as a fact about the order.
 *
 * ## Why this is not a filter
 *
 * `practice_order_topics` is unique on `(store_source_id, source_order_id)` and is written by
 * upsert: one topic per source order, and no history of what it was before. The Practice Results
 * page is one source order, so every result on it carries the same topic — a dropdown offering it
 * would have one option that excludes nothing.
 *
 * So the page shows it as context, which is the question a reviewer actually has: what was this
 * practice about? Genuine cross-topic reporting belongs on a view spanning several source orders,
 * and even there it can only ever filter by TODAY's topic — re-assigning one silently rewrites what
 * every past result appears to have been practised under. That is the data model, and inventing a
 * history for it here would be fabricating attribution.
 *
 * ## Why an interface in this module
 *
 * The topic tables belong to `App\Practice`. This module reads them **by table name** through its
 * own port, the same way Audio-to-Text reads this module's tables — so neither module names the
 * other and the boundary tests stay honest.
 */
interface PracticeTopicReaderInterface
{
    /** The order's current topic name, or null where it has none. */
    public function forOrder(int $storeSourceId, int $sourceOrderId): ?string;
}
