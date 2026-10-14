<?php

declare(strict_types=1);

namespace App\Practice\Domain;

/**
 * Which of these order ids really are orders of this store.
 *
 * A port rather than a call into the Order Testing module: this module reads one fact from the orders
 * mirror and gains no dependency on the module that owns it, which is the same arrangement
 * `DemoOrderCountReaderInterface` uses in the other direction.
 *
 * Both ids, always. An order id alone is not unique across stores — Order58 publishes no such contract
 * — so a check that ignored the store would let a crafted request put a topic on another merchant's
 * order.
 */
interface SourceOrderExistenceInterface
{
    /**
     * @param list<int> $sourceOrderIds
     *
     * @return list<int> the subset that exists in this store, in no particular order
     */
    public function existingOrderIds(int $storeSourceId, array $sourceOrderIds): array;
}
