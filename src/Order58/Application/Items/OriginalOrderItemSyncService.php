<?php

declare(strict_types=1);

namespace App\Order58\Application\Items;

use App\Order58\Client\Items\OrderItemClientInterface;
use App\Order58\Client\Items\OrderItemDataFailed;
use App\Order58\Client\Items\OrderItemSettings;
use App\Order58\Domain\Items\EnrolmentOutcome;
use App\Order58\Domain\Items\ItemSyncBatchRepositoryInterface;
use App\Order58\Domain\Items\ItemSyncStateRepositoryInterface;
use App\Order58\Domain\Items\OrderItemNotMappable;
use App\Order58\Domain\Items\OrderItemRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

use function bin2hex;
use function count;
use function hash;
use function min;
use function random_bytes;
use function sprintf;

/**
 * Fetch one order's items and make the stored snapshot match — the single implementation.
 *
 * ## One service, three callers
 *
 * The background drainer, the Orders-page button and the comparison-page button all call
 * {@see syncOrder()}. There is deliberately no second code path for "manual": a manual refresh is the
 * same work with a wider claim predicate, which is the only thing that differs and is expressed as the
 * `$manual` flag below. Two implementations would be two sets of rules about when it is safe to delete
 * an order's items.
 *
 * ## The order of operations is the safety
 *
 *  0. **Check availability** — switched off or unconfigured returns `Unavailable` having written
 *     nothing at all: no claim, no attempt, no status change.
 *  1. **Claim** — atomic, and the only way in. A live lease elsewhere means `Busy` and **no request**.
 *  2. **Fetch** — outside any transaction, so a slow provider holds no row locks.
 *  3. **Compare hashes** — an unchanged response writes no item rows, but still records freshness.
 *  4. **Map everything, or nothing** — one unmappable line fails the whole response.
 *  5. **Replace** — in one transaction, re-checking ownership *before* the first delete.
 *  6. **Record** — `SYNCED`/`EMPTY` on success, `FAILED` on anything else.
 *
 * ## A failure never costs the previous snapshot
 *
 * Every error path reaches {@see ItemSyncStateRepositoryInterface::markFailed()}, which leaves
 * `items_synced_at`, `items_count` and the stored items alone. "We could not refresh" and "this order
 * has no items" are different facts and only the provider saying `OrderItems: []` means the second.
 */
final readonly class OriginalOrderItemSyncService implements OrderItemSyncInterface
{
    /** Statuses the background worker may take. Only work it has been given. */
    private const WORKER_CLAIMS = ['QUEUED'];

    /**
     * Statuses a person pressing the button may take.
     *
     * Includes `QUEUED`, which is what lets a manual refresh jump the queue rather than waiting for the
     * next worker tick, and `PENDING`, so an order nobody has ever queued can still be refreshed on
     * demand. `PROCESSING` is absent: a live lease is somebody else's, and the claim's lease check
     * enforces that regardless.
     */
    private const MANUAL_CLAIMS = ['PENDING', 'QUEUED', 'SYNCED', 'EMPTY', 'FAILED'];

    /** The sentence an operator sees. Never the variable names — those go to the log. */
    private const UNAVAILABLE = 'Original Order Items synchronization is not enabled or configured.';

    public function __construct(
        private OrderItemClientInterface $client,
        private OrderItemRepositoryInterface $items,
        private ItemSyncStateRepositoryInterface $state,
        private OrderItemMapper $mapper,
        private OrderItemSettings $settings,
        /**
         * Per-operation progress.
         *
         * Settled HERE rather than by the callers, because this is the one place a provider request
         * reaches a terminal outcome. Two overlapping batches can be waiting on the same order; one
         * request is made, and every batch waiting on it learns that one result.
         */
        private ItemSyncBatchRepositoryInterface $batches,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $leaseSeconds,
        private int $maxAttempts,
    ) {}

    /**
     * @param bool $manual true for a button press, false for the background drainer
     */
    public function syncOrder(int $accountId, int $sourceOrderId, bool $manual = false): ItemSyncOutcome
    {
        $now = $this->clock->now();

        // BEFORE the claim, deliberately.
        //
        // The client refuses an unconfigured request too, but by then the order has been claimed —
        // which means `PROCESSING`, a spent attempt, and then `FAILED` with a retry clock. That
        // records a provider problem that never happened: the feature simply is not switched on. An
        // order must look untouched after a click that could not do anything, so this returns before
        // a single row is written.
        $problems = $this->settings->problems();

        if ($problems !== []) {
            // Logged, not shown: `problems()` yields setting NAMES, which belong in an administrator's
            // log rather than in a flash message on a page about one customer's order.
            $this->logger->info('Order58 item sync was asked for while unavailable.', [
                'reason' => 'order58_item_sync_unavailable',
                'account_id' => $accountId,
                'source_order_id' => $sourceOrderId,
                'problems' => $problems,
            ]);

            return ItemSyncOutcome::unavailable(self::UNAVAILABLE);
        }

        // Unique per attempt. Two runs of the same worker get different tokens, so a stale one can
        // never satisfy the ownership checks that follow.
        $token = bin2hex(random_bytes(16));

        $claim = $this->state->claim(
            $accountId,
            $sourceOrderId,
            $token,
            $manual ? self::MANUAL_CLAIMS : self::WORKER_CLAIMS,
            $now,
            $this->leaseSeconds,
        );

        if ($claim === null) {
            // Either somebody holds a live lease, or the status was not one this caller may take.
            // Both mean: make no request.
            return ItemSyncOutcome::busy();
        }

        try {
            $response = $this->client->listItems($accountId, $sourceOrderId);
        } catch (OrderItemDataFailed $e) {
            return $this->fail($accountId, $sourceOrderId, $token, $claim->attempts, $e->getMessage(), $now);
        }

        $responseHash = hash('sha256', $response['body']);

        if ($claim->responseHash === $responseHash && $claim->hasSnapshot) {
            // Byte-identical to what produced the stored rows. No item write — but freshness, the
            // attempt counter and the lease all still have to be settled, or "nothing changed" would
            // leave the order looking stuck in PROCESSING forever.
            $count = count($response['items']);
            $this->state->markSynced(
                $accountId,
                $sourceOrderId,
                $token,
                $count,
                $responseHash,
                $response['body'],
                $now,
            );

            return $this->settled($accountId, $sourceOrderId, ItemSyncOutcome::unchanged($count), $now);
        }

        try {
            $items = [];
            foreach ($response['items'] as $position => $raw) {
                // All or nothing: one unmappable line fails the response, because storing the rest
                // would silently delete that line from an order that still has it.
                $items[] = $this->mapper->toItem($accountId, $sourceOrderId, $raw, $position);
            }
        } catch (OrderItemNotMappable $e) {
            return $this->fail($accountId, $sourceOrderId, $token, $claim->attempts, $e->getMessage(), $now);
        }

        try {
            $replaced = $this->items->replace($accountId, $sourceOrderId, $items, $token, $now);
        } catch (Throwable $e) {
            // The message is this application's own: a driver exception can carry parameter values,
            // and those are a customer's order.
            $this->logger->error('Order58 item snapshot could not be written.', [
                'reason' => 'order58_item_write_failed',
                'account_id' => $accountId,
                'source_order_id' => $sourceOrderId,
                'error_class' => $e::class,
            ]);

            return $this->fail(
                $accountId,
                $sourceOrderId,
                $token,
                $claim->attempts,
                'The item snapshot could not be written. The items already stored were kept.',
                $now,
            );
        }

        if (!$replaced) {
            // The lease moved on while we were fetching. Nothing was deleted and nothing inserted —
            // whoever holds it now will finish the job.
            return ItemSyncOutcome::busy();
        }

        $count = count($items);
        $this->state->markSynced($accountId, $sourceOrderId, $token, $count, $responseHash, $response['body'], $now);

        return $this->settled(
            $accountId,
            $sourceOrderId,
            $count === 0 ? ItemSyncOutcome::empty() : ItemSyncOutcome::synced($count),
            $now,
        );
    }

    /**
     * Tell every batch waiting on this order what the one request for it came to.
     *
     * ## Why it is here and not in the callers
     *
     * This is the single place a provider request reaches a terminal outcome. Settling from the
     * callers would mean the console command, the web action and the drainer each remembering to do
     * it — and the one that forgot would leave batches running forever over work that had finished.
     *
     * `Busy` and `Unavailable` never reach this method: neither is terminal. Busy means another
     * caller holds the claim and will settle it; unavailable means nothing was attempted at all.
     *
     * ## A later success corrects an earlier failure
     *
     * A failed request settles its enrolments as `FAILED` so a batch can reach a verdict instead of
     * hanging on an order the provider keeps refusing. When a retry succeeds, those batches are now
     * reporting something untrue, so the success also reconciles them — see
     * {@see ItemSyncBatchRepositoryInterface::reconcileAfterSuccess()}.
     *
     * Progress is never allowed to break a sync: a failure to record it is logged and swallowed. The
     * items are already stored correctly by this point, and losing a progress row is a worse thing to
     * report to the operator than it is to fix later.
     */
    private function settled(
        int $accountId,
        int $sourceOrderId,
        ItemSyncOutcome $outcome,
        DateTimeImmutable $now,
    ): ItemSyncOutcome {
        $enrolment = EnrolmentOutcome::fromSyncResult($outcome->result);

        if ($enrolment === null) {
            return $outcome;
        }

        try {
            $this->batches->settle($accountId, $sourceOrderId, $enrolment, $outcome->error, $now);

            if (!$enrolment->isFailure()) {
                $this->batches->reconcileAfterSuccess($accountId, $sourceOrderId, $enrolment, $now);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Order58 item batch progress could not be recorded.', [
                'reason' => 'order58_item_batch_settle_failed',
                'account_id' => $accountId,
                'source_order_id' => $sourceOrderId,
                'error_class' => $e::class,
            ]);
        }

        return $outcome;
    }

    /**
     * Record a failure and work out when, if ever, to try again.
     *
     * Exponential with a ceiling, the same shape `IntegrationSyncDrainer` already uses. Past the
     * attempt cap `retry_after` is null, which takes the order out of the automatic queue entirely and
     * leaves it for a person — a provider that is refusing us is not fixed by asking again.
     */
    private function fail(
        int $accountId,
        int $sourceOrderId,
        string $token,
        int $attempts,
        string $error,
        DateTimeImmutable $now,
    ): ItemSyncOutcome {
        $exhausted = $attempts >= $this->maxAttempts;

        $retryAfter = $exhausted
            ? null
            : $now->modify(sprintf('+%d seconds', min(3600, 60 * (2 ** $attempts))));

        $this->state->markFailed($accountId, $sourceOrderId, $token, $error, $retryAfter, $now);

        return $this->settled($accountId, $sourceOrderId, ItemSyncOutcome::failed($error), $now);
    }
}
