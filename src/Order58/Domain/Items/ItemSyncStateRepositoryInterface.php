<?php

declare(strict_types=1);

namespace App\Order58\Domain\Items;

use DateTimeImmutable;

/**
 * The per-order item-sync state, which lives in columns on `order58_orders`.
 *
 * ## Why claiming is the only way in
 *
 * Two callers can want the same order at the same moment: the background worker draining the queue,
 * and an administrator pressing "Sync Order Items". The worker runs under a flock; the web request
 * does not. So the flock cannot be the whole answer, and the claim below is — an atomic UPDATE whose
 * WHERE clause carries the precondition, so exactly one caller gets a row.
 *
 * Attempts increment **at claim**, not at failure. A process killed mid-request has still used an
 * attempt, which is what stops a poison order looping forever.
 */
interface ItemSyncStateRepositoryInterface
{
    /**
     * Take ownership of one order, or fail.
     *
     * `$fromStatuses` is the precondition, and it is the whole difference between the two callers:
     * the worker claims only `QUEUED`, while a manual refresh also claims `PENDING`, `SYNCED`,
     * `EMPTY` and `FAILED` — which is how pressing the button jumps the queue instead of waiting for
     * the next tick. Neither can claim an order whose lease is still live.
     *
     * @param list<string> $fromStatuses
     *
     * @return ItemSyncState|null null when another caller holds it
     */
    public function claim(
        int $accountId,
        int $sourceOrderId,
        string $leaseOwner,
        array $fromStatuses,
        DateTimeImmutable $now,
        int $leaseSeconds,
    ): ?ItemSyncState;

    /**
     * Record a completed sync: `SYNCED` or `EMPTY`, depending on what arrived.
     *
     * Always sets `items_synced_at`, including when the response was unchanged — "nothing was written"
     * must still mean "we checked just now", or freshness would stop moving the moment an order
     * stopped changing.
     */
    public function markSynced(
        int $accountId,
        int $sourceOrderId,
        string $leaseOwner,
        int $itemCount,
        string $responseHash,
        string $responseJson,
        DateTimeImmutable $now,
    ): void;

    /**
     * Record a failure, keeping everything about the previous snapshot.
     *
     * `items_synced_at`, `items_count` and `items_response_json` are **not** touched: they are what the
     * page uses to say "showing items from <date>, the latest refresh failed" rather than pretending
     * there is nothing there.
     */
    public function markFailed(
        int $accountId,
        int $sourceOrderId,
        string $leaseOwner,
        string $error,
        ?DateTimeImmutable $retryAfter,
        DateTimeImmutable $now,
    ): void;

    /**
     * Return orders whose lease has run out to `QUEUED`, clearing the owner token.
     *
     * Clearing the token is the point, not a tidy-up: a stale holder's token must stop matching, or it
     * could still pass the ownership check inside a replacement transaction and overwrite newer rows.
     *
     * @return int how many were recovered
     */
    public function expireLeases(DateTimeImmutable $now): int;

    /**
     * Mark orders as needing their items fetched. **Local statements only; never any HTTP.**
     *
     * Called from inside `OrderSyncService` for every order a sync received — changed and unchanged
     * alike. That breadth is deliberate and is the correction that matters: the service `continue`s
     * past an unchanged order *before* it writes, so an enqueue tied to the write would never reach a
     * historical order, which is most of them.
     *
     * What each list means:
     *
     *  - **both lists**: an order with no sync row yet, or still `PENDING`, becomes `QUEUED`. So a
     *    historical order is queued the moment an operator syncs a range that includes it — and an
     *    order outside that range is not in the response at all, so it is never touched.
     *  - **both lists**: a `FAILED` order whose `retry_after` has passed and whose attempts are below
     *    the cap becomes `QUEUED` again.
     *  - **`$changedIds` only**: an order whose parent payload moved is re-queued even though it is
     *    already `SYNCED` or `EMPTY`, because the items may have moved with it.
     *
     * An order that is `SYNCED`/`EMPTY` with an unchanged parent is deliberately left alone — that is
     * what stops an ordinary sync spending one API request per order, every time.
     *
     * Idempotent: an order already `QUEUED` or `PROCESSING` is never touched, so running the same sync
     * twice queues no duplicate work.
     *
     * @param list<int> $changedIds   orders whose parent payload hash moved
     * @param list<int> $unchangedIds orders the sync saw but did not rewrite
     *
     * @return int how many orders were newly queued
     */
    public function enqueue(
        int $accountId,
        array $changedIds,
        array $unchangedIds,
        int $maxAttempts,
        DateTimeImmutable $now,
    ): int;

    /**
     * Orders waiting for their items, oldest first.
     *
     * Candidates, not claims: the caller still has to win {@see claim()}, which is what settles a race
     * between two drainers or a drainer and a button press. Returning candidates keeps the queue scan
     * and the claim in one place each rather than smearing both across a cursor.
     *
     * @return list<array{accountId: int, sourceOrderId: int}>
     */
    public function nextQueued(int $limit, DateTimeImmutable $now): array;

    /**
     * The current item state of specific orders, for one account.
     *
     * Read straight after an enqueue so a batch can enrol each order honestly: an order now `QUEUED`
     * is work the batch must wait for, while one still `SYNCED` needs no request and one left `FAILED`
     * past its attempt cap will never be retried automatically. Enrolling all three the same way would
     * either overstate the work or hang the batch on an order nobody will fetch.
     *
     * One query for a whole chunk, not one per order.
     *
     * @param list<int> $sourceOrderIds
     *
     * @return array<int, array{status: string, error: string|null}> keyed by source order id
     */
    public function statusesFor(int $accountId, array $sourceOrderIds): array;
}
