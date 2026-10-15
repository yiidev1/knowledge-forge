<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

/**
 * Whether one order really is an order of one store.
 *
 * Separate from {@see SourceOrderReaderInterface}, which decodes the whole stored payload so a demo
 * order can be compared against it. Verification needs one fact and no columns, and asking the heavy
 * reader for it would decode a complete order object to answer yes or no.
 *
 * Both ids, always. An order id alone is not unique across stores — Order58 publishes no such contract
 * — so a check that ignored the store would let a crafted request verify another merchant's order.
 */
interface SourceOrderExistenceInterface
{
    public function exists(int $storeSourceId, int $sourceOrderId): bool;
}
