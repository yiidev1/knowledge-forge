<?php

declare(strict_types=1);

namespace App\Practice\Domain;

/**
 * Which topic each source order is being practised under.
 *
 * Every method takes the store id as well as the order id. That is not convenience — a lookup by order
 * id alone would let one merchant's page show, or change, another merchant's assignment, which is the
 * same rule `SourceOrderReaderInterface` states for the orders themselves.
 */
interface TopicAssignmentRepositoryInterface
{
    /**
     * The assignments for a page of orders, keyed by source order id.
     *
     * One query for the whole page. The store page renders fifteen rows and a lookup per row would be
     * fifteen queries for a single column.
     *
     * @param list<int> $sourceOrderIds
     *
     * @return array<int, TopicAssignment>
     */
    public function forOrders(int $storeSourceId, array $sourceOrderIds): array;

    /**
     * Put a topic on a source order, replacing whatever was there.
     *
     * An upsert on the unique pair, so two administrators assigning at the same moment produce one row
     * rather than a race, and reassignment keeps the row's identity.
     */
    public function assign(int $storeSourceId, int $sourceOrderId, int $topicId, AssignedBy $by): void;

    /**
     * The same, for many orders at once, in one transaction.
     *
     * All or nothing: a partial success would leave an operator unable to tell which half worked.
     *
     * @param list<int> $sourceOrderIds already de-duplicated and already proven to belong to the store
     */
    public function assignMany(int $storeSourceId, array $sourceOrderIds, int $topicId, AssignedBy $by): int;

    public function unassign(int $storeSourceId, int $sourceOrderId): void;

    /**
     * The source order ids in this store that carry this topic — or that carry none, for `$topicId`
     * null. Used to filter a store's order list before it is paged.
     *
     * @return list<int>
     */
    public function orderIdsWithTopic(int $storeSourceId, ?int $topicId): array;
}
