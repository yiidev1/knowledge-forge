<?php

declare(strict_types=1);

namespace App\Order58\Domain\CallRecordings;

use DateTimeImmutable;

/**
 * Case Order call sessions and their recording links (`order58_call_sessions`, `order58_call_recordings`).
 *
 * Reads are batched by `(store, order)` pairs so a synchronisation of two hundred orders costs a fixed
 * handful of queries rather than a few per order.
 */
interface CallRecordingRepositoryInterface
{
    /**
     * Which of these orders are actually stored, keyed by {@see OrderCalls::keyOf()}.
     *
     * A session row needs its order to exist; an order the import could not write is skipped rather
     * than allowed to fail on the foreign key.
     *
     * @param list<OrderCalls> $orders
     *
     * @return array<string, true>
     */
    public function storedOrders(array $orders): array;

    /**
     * The stored sessions of these orders, with their recordings, keyed by {@see OrderCalls::keyOf()}
     * and then by provider session id.
     *
     * @param list<OrderCalls> $orders
     *
     * @return array<string, array<string, StoredCallSession>>
     */
    public function sessionsFor(array $orders): array;

    /** Apply one order's changes in a single transaction. */
    public function apply(int $storeSourceId, int $sourceOrderId, OrderRecordingChanges $changes, DateTimeImmutable $now): void;

    /**
     * One current recording, found only if it belongs to this order of this store.
     *
     * All three ids are required together, so an id taken from one order's page cannot be replayed
     * against another order or another store. A link the provider has since replaced is not served.
     */
    public function locate(int $storeSourceId, int $sourceOrderId, int $recordingId): ?RecordingLocation;

    /**
     * The `calls` already stored in `order58_orders.payload_json`, a page at a time, for the backfill.
     *
     * Only `$.calls` is extracted, never the whole payload: the rest of the document is customer data
     * the backfill has no use for.
     *
     * @param int      $afterRowId     keyset paging on `order58_orders.id`
     * @param int|null $storeSourceId  one store only, or every store
     *
     * @return list<StoredOrderCalls>
     */
    public function storedCallsPage(int $afterRowId, int $limit, ?int $storeSourceId = null): array;
}
