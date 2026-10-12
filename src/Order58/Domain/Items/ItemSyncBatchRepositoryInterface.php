<?php

declare(strict_types=1);

namespace App\Order58\Domain\Items;

use DateTimeImmutable;

/**
 * Per-operation progress for original-order-item synchronisation.
 *
 * ## The one rule everything here serves
 *
 * A batch is finished when it has no unsettled enrolment rows. Nothing increments a counter, and
 * nothing sets an outcome because an order happened to finish — {@see progressFor()} recomputes the
 * verdict from the rows every time it is asked. A process killed mid-operation therefore leaves no
 * wrong number behind, only rows that are still unsettled.
 */
interface ItemSyncBatchRepositoryInterface
{
    /**
     * Open a batch for one user-initiated operation.
     *
     * @param string $triggerSource `orders_sync` or `recording_sync` — which action opened it
     */
    public function open(
        int $storeAccountId,
        string $triggerSource,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $requestedByAdminId,
        DateTimeImmutable $now,
    ): int;

    /**
     * Enrol the orders this operation is waiting on, and record the scope it intended to cover.
     *
     * Each id is enrolled with the order's CURRENT `items_status`, which decides whether the batch has
     * anything to wait for. An order already `SYNCED` or `EMPTY` whose parent did not move is enrolled
     * {@see EnrolmentOutcome::Skipped} and settled immediately: no request will be made for it, so
     * leaving it pending would block a batch forever.
     *
     * `$eligibleCount` is what the sync INTENDED to enrol. When it is larger than the number of ids —
     * because the sync ran out of time — the batch is marked truncated, and its verdict can never be
     * `Completed`. An incomplete scope must not read as a finished one.
     *
     * Idempotent: enrolling the same order twice in one batch is one row.
     *
     * @param array<int, EnrolmentOutcome|null> $orders source order id => how it enrols. `null` means
     *                                                   this batch is waiting on a provider request for
     *                                                   it; anything else settles on arrival
     * @param array<int, string|null>            $errors source order id => the error to record for an
     *                                                   order enrolled as already failed
     */
    public function enrol(
        int $batchId,
        int $storeAccountId,
        array $orders,
        array $errors,
        int $eligibleCount,
        bool $ordersTruncated,
        DateTimeImmutable $now,
    ): void;

    /**
     * Record what the Orders half of the operation did, before any item work.
     *
     * Separate from the item counters because the two halves fail independently: an Orders Sync can
     * succeed completely while every item request afterwards fails, and the page has to be able to say
     * so rather than blaming one for the other.
     */
    public function recordOrdersResult(
        int $batchId,
        int $ordersReceived,
        int $ordersSaved,
        bool $truncated,
        ?string $error,
        DateTimeImmutable $now,
    ): void;

    /**
     * Settle every open enrolment for one order with the result of the single request made for it.
     *
     * **This is how overlapping batches share one provider request.** The fetch is keyed on the order
     * and serialised by the claim on `order58_orders`; when it reaches a terminal outcome, every batch
     * still waiting on that order learns the same result here. No batch triggers a second request, and
     * none reports completion for work it did not wait for.
     *
     * @return int how many enrolments were settled
     */
    public function settle(
        int $storeAccountId,
        int $sourceOrderId,
        EnrolmentOutcome $outcome,
        ?string $error,
        DateTimeImmutable $now,
    ): int;

    /**
     * Reconcile a previously failed enrolment after a later refresh succeeds.
     *
     * ## Why this exists, and what it deliberately does not do
     *
     * A failed request settles its enrolment as `Failed` rather than leaving it pending, so a batch can
     * reach a verdict instead of hanging on an order the provider keeps refusing. When a retry later
     * succeeds, the stored items become correct — and a batch still reading `PARTIAL` because of that
     * order is now reporting something that is no longer true.
     *
     * So a success rewrites `Failed` enrolments for that order to the new outcome and clears the error,
     * which lets {@see progressFor()} recompute the batch as `Completed`.
     *
     * What it does **not** do is touch `settled_at`, or any enrolment that did not fail. The history
     * that matters — when this batch stopped waiting — is preserved; only the verdict about the order
     * is corrected. And it never reopens a settled row, because a batch that has already been reported
     * finished must not silently start running again.
     *
     * @return int how many previously failed enrolments were corrected
     */
    public function reconcileAfterSuccess(
        int $storeAccountId,
        int $sourceOrderId,
        EnrolmentOutcome $outcome,
        DateTimeImmutable $now,
    ): int;

    /**
     * One batch's progress, recomputed from its rows.
     *
     * `$stalledAfterSeconds` is how long without a settlement counts as stalled. Stalled is a display
     * state, never a verdict: nothing is known to have failed, only that nothing has moved — most often
     * because the importer is not installed or not running.
     */
    public function progressFor(
        int $batchId,
        DateTimeImmutable $now,
        int $stalledAfterSeconds,
    ): ?ItemSyncBatchProgress;

    /**
     * Write the derived verdict back, so a finished batch stops being recomputed as RUNNING.
     *
     * Called after settlement. It is a cache of {@see progressFor()}, never the source: a batch whose
     * stored outcome disagrees with its rows is corrected the next time it is read.
     */
    public function refreshOutcome(int $batchId, DateTimeImmutable $now): BatchOutcome;

    /** The most recent batch for a store, for a page that has no batch id in its URL. */
    public function latestFor(int $storeAccountId): ?int;
}
