<?php

declare(strict_types=1);

namespace App\Order58\Domain\Items;

/**
 * How far original-order-item synchronisation has got, across the orders a listing is showing.
 *
 * Counts only. The items themselves, and the decision about whether they may be believed, belong to
 * the readers that serve the comparison page; this answers one question for one progress bar.
 */
interface ItemSyncSummaryReaderInterface
{
    /**
     * One query, whatever the scope contains.
     *
     * @param int|null $storeSourceId one store, or null for every stored order — matching the listing's
     *                                own store filter. See {@see ItemSyncSummary} for why the date and
     *                                order-id filters deliberately do not narrow this.
     */
    public function summaryFor(?int $storeSourceId): ItemSyncSummary;
}
