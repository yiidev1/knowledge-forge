<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * Whether each source order on a page has been verified.
 *
 * A port of this module's own, reading another module's table by name, exactly as
 * {@see TopicAssignmentReaderInterface} and {@see DemoOrderCountReaderInterface} do. The Order Testing
 * page lives in this module because nothing outside it may name `App\AudioToText`; reading the
 * verification table through an interface here keeps the dependency from running the other way too.
 *
 * Both ids, always. An order id alone is not unique across stores.
 */
interface OrderVerificationReaderInterface
{
    /**
     * One query for the whole page, keyed by source order id.
     *
     * Orders with no row are simply absent from the result: no row means unverified, which the caller
     * already knows how to say. Twenty rows must cost one query, not twenty.
     *
     * @param list<string> $orderIds as the rows carry them: free text, not yet known to be numbers
     *
     * @return array<string, OrderVerificationStatus> keyed by the same strings that were asked for
     */
    public function forOrders(int $storeSourceId, array $orderIds): array;
}
