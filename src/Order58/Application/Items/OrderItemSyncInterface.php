<?php

declare(strict_types=1);

namespace App\Order58\Application\Items;

/**
 * Synchronise one order's original items.
 *
 * The one thing the background drainer and both manual web actions need, named so none of them depends
 * on the concrete service. {@see OriginalOrderItemSyncService} is the only production implementation,
 * and it stays `final` — a collaborator that has to be substituted in a test is a reason for an
 * interface, never a reason to unseal a class.
 */
interface OrderItemSyncInterface
{
    /**
     * @param bool $manual true for a button press, which may claim an order the worker could not
     */
    public function syncOrder(int $accountId, int $sourceOrderId, bool $manual = false): ItemSyncOutcome;
}
