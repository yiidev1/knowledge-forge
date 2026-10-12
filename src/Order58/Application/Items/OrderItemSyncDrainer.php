<?php

declare(strict_types=1);

namespace App\Order58\Application\Items;

use App\Order58\Domain\Items\ItemSyncStateRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Worker\Application\DrainResult;
use App\Worker\Application\DrainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drains queued original-item work inside the existing `kf:worker:run`.
 *
 * ## No new timer, cron, service or daemon
 *
 * `kf:worker:run` already runs every minute under its own flock, already has an ordered drainer list,
 * and already contains an Order58 drainer. `DrainerInterface` already encodes the exact lifecycle this
 * work needs — `recover()` then a bounded `drain()` — and `WorkerParams` already carries the batch
 * size. Adding a Redis queue or a second timer would be building infrastructure that is already here
 * and running.
 *
 * ## Cheap on purpose, and placed early
 *
 * One item fetch is a small POST and a handful of local writes. It sits ahead of document processing
 * in the drainer list so a trainee's items are not waiting behind an OpenAI indexing run.
 *
 * ## What it will not do
 *
 * It claims only `QUEUED` orders. It never promotes `PENDING` — that promotion belongs to an order
 * sync an operator actually asked for, which is what keeps the historical backlog from draining itself
 * the moment this ships. And when the feature is switched off it does nothing at all: the service
 * answers `Unavailable` before claiming anything, so the batch stops on the first candidate and no
 * order is left marked as having failed something that was never attempted.
 */
final readonly class OrderItemSyncDrainer implements DrainerInterface
{
    public function __construct(
        private ItemSyncStateRepositoryInterface $state,
        private OrderItemSyncInterface $sync,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function name(): string
    {
        return 'order58-order-items';
    }

    /**
     * Return orders stranded by a crashed run.
     *
     * A process killed mid-fetch leaves its order `PROCESSING` with a lease nobody will release. Once
     * that lease expires this puts the order back in the queue **and clears the owner token**, so the
     * dead run's token can no longer satisfy the ownership check inside a replacement transaction.
     * The attempt it spent is not refunded, which is what bounds a poison order.
     */
    public function recover(): void
    {
        try {
            $recovered = $this->state->expireLeases($this->clock->now());

            if ($recovered > 0) {
                $this->logger->info('Order58 item syncs recovered from an expired lease.', [
                    'reason' => 'order58_item_lease_recovered',
                    'count' => $recovered,
                ]);
            }
        } catch (Throwable $e) {
            // Recovery failing must not stop the drain, and must not stop the other drainers.
            $this->logger->warning('Order58 item lease recovery failed.', [
                'reason' => 'order58_item_recover_failed',
                'error_class' => $e::class,
            ]);
        }
    }

    public function drain(int $limit): DrainResult
    {
        $now = $this->clock->now();

        try {
            $candidates = $this->state->nextQueued($limit, $now);
        } catch (Throwable $e) {
            $this->logger->warning('Order58 item queue could not be read.', [
                'reason' => 'order58_item_queue_read_failed',
                'error_class' => $e::class,
            ]);

            return DrainResult::nothing();
        }

        $processed = 0;
        $failed = 0;

        foreach ($candidates as $candidate) {
            try {
                $outcome = $this->sync->syncOrder($candidate['accountId'], $candidate['sourceOrderId']);
            } catch (Throwable $e) {
                // One order's problem is one order's problem; the batch continues. The message is this
                // application's own — a driver exception can carry a customer's order in its parameters.
                ++$failed;
                $this->logger->error('Order58 item sync threw.', [
                    'reason' => 'order58_item_sync_threw',
                    'account_id' => $candidate['accountId'],
                    'source_order_id' => $candidate['sourceOrderId'],
                    'error_class' => $e::class,
                ]);

                continue;
            }

            // Busy is neither progress nor failure: somebody else holds the order — most likely an
            // administrator who pressed the button — and they will finish it.
            if ($outcome->result === ItemSyncResult::Busy) {
                continue;
            }

            // Unavailable is a fact about the installation, not about this order, so every remaining
            // candidate would answer the same way. Stop the batch rather than log the same sentence
            // once per queued order — and count nothing, because nothing was attempted.
            if ($outcome->result === ItemSyncResult::Unavailable) {
                break;
            }

            ++$processed;

            if ($outcome->result === ItemSyncResult::Failed) {
                ++$failed;
            }
        }

        return new DrainResult($processed, $failed);
    }
}
