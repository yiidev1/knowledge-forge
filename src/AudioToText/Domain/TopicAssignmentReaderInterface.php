<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * The topic each source order on a page is being practised under.
 *
 * A port of this module's own, reading another module's table by name, exactly as
 * {@see DemoOrderCountReaderInterface} does. The Practice Orders page lives in this module because
 * nothing outside it may name `App\AudioToText`; reading the topic tables through an interface here
 * keeps the dependency from running the other way as well.
 *
 * Both ids, always. An order id alone is not unique across stores.
 */
interface TopicAssignmentReaderInterface
{
    /**
     * One query for the whole page, keyed by source order id.
     *
     * @param list<string> $orderIds as the rows carry them: free text, not yet known to be numbers
     *
     * @return array<string, AssignedTopic> keyed by the same strings that were asked for
     */
    public function forOrders(int $storeSourceId, array $orderIds): array;

    /**
     * Every topic that may be offered in the assignment dialog, in name order.
     *
     * Active ones only: this is the list of choices, and a choice that would be refused on submission
     * does not belong in it.
     *
     * @return list<AssignedTopic>
     */
    public function assignableTopics(): array;
}
