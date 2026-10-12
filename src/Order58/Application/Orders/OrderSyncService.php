<?php

declare(strict_types=1);

namespace App\Order58\Application\Orders;

use App\Order58\Application\ImportRunRequest;
use App\Order58\Client\Orders\OrderDataClientInterface;
use App\Order58\Domain\Items\EnrolmentOutcome;
use App\Order58\Domain\Items\ItemSyncBatchRepositoryInterface;
use App\Order58\Domain\Items\ItemSyncStateRepositoryInterface;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

use function array_chunk;
use function count;
use function array_key_exists;
use function microtime;
use function sprintf;

/**
 * One click: call the Orders API, map what comes back, write what changed, report what happened.
 *
 * Synchronous by design — it runs inside the web request that asked for it, and returns the real counts
 * rather than a job id. The whole point is that the operator learns the outcome, not that a worker will
 * get to it.
 *
 * ## The deadline is what keeps that honest
 *
 * The web server gives a request 60 seconds. This service holds itself to a budget below that and checks
 * it **between chunks**, so a run that is going to be too slow stops on its own and says so, rather than
 * being cut off by the server mid-write with nobody able to say what was saved.
 *
 * It is a floor, not a guarantee: a chunk already in flight finishes, and the one call to the provider
 * can take up to its own timeout. Those are bounded (a chunk is 50 upserts; the call has a configured
 * ceiling), but a pathological provider plus a pathological chunk can still overshoot the budget. The
 * design makes that overshoot small and bounded — it does not make it impossible.
 *
 * ## Why change detection reads before it writes
 *
 * One query fetches the stored hashes for a whole chunk. An order whose hash matches is skipped
 * entirely — no UPDATE, no row touched, counted `unchanged`. That is what makes running the same sync
 * twice cost almost nothing, which is the behaviour an operator relies on when a page times out and
 * they press the button again.
 */
final readonly class OrderSyncService
{
    /** Small enough that one in-flight chunk cannot overshoot the deadline by much. */
    private const CHUNK = 50;

    public function __construct(
        private OrderDataClientInterface $client,
        private Order58OrderRepositoryInterface $orders,
        private OrderMapper $mapper,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        /**
         * The one lock for a store and date range, held for the whole run.
         *
         * Here rather than in the web action because the action is not the only caller:
         * `kf:order58:import-recordings` calls this service directly after a settled recording batch,
         * and that path acquired nothing. See {@see OrderSyncLockInterface}.
         */
        private OrderSyncLockInterface $lock,
        /**
         * Where item work is registered. **Local statements only — no HTTP ever happens here.**
         *
         * Both entry points funnel through this service, so putting the enqueue here is what covers the
         * recording worker as well as the Orders button, by construction rather than by remembering.
         */
        private ItemSyncStateRepositoryInterface $itemSyncs,
        private ItemSyncBatchRepositoryInterface $batches,
        /**
         * Asks the importer to run now. A hint, never a dependency — see ImportRunRequest.
         *
         * A write that lands while the importer is already running is DISCARDED by systemd's `.path`
         * unit; measured, not assumed. The existing timer is what closes that gap, which is why this
         * is an optimisation and the timer is the guarantee.
         */
        private ImportRunRequest $runRequest,
        private int $deadlineSeconds,
        /** Shared with the item sync service, so "due a retry" means one thing. */
        private int $itemMaxAttempts,
    ) {}

    /**
     * @throws OrderSyncInProgress when another run already holds this store and range
     */
    /**
     * @param string $triggerSource which user action this run belongs to, for its progress batch:
     *                              `orders_sync` for the button, `recording_sync` for the importer.
     *                              It labels progress only — the work and the lock are identical.
     */
    public function sync(
        int $accountId,
        OrderDateRange $range,
        string $triggerSource = 'orders_sync',
    ): OrderSyncSummary {
        $name = self::lockName($accountId, $range);

        if (!$this->lock->acquire($name)) {
            throw new OrderSyncInProgress($accountId, $range);
        }

        // Opened before any work, so even a provider failure leaves a record saying the operation was
        // attempted and what became of it.
        $batchId = $this->openBatch($accountId, $range, $triggerSource);

        try {
            $summary = $this->run($accountId, $range, $batchId);
            $summary->batchId = $batchId;

            $this->recordOrders($batchId, $summary->received, $summary->created + $summary->updated, $summary->stoppedEarly, null);

            // Only a hint, and only when there is something to fetch. See ImportRunRequest.
            if ($batchId !== null) {
                $this->runRequest->requestRun();
            }

            return $summary;
        } catch (\Throwable $e) {
            // The Orders half failed outright, so no scope was ever enrolled. Recorded as such rather
            // than left RUNNING forever, which is what a watching page would otherwise poll.
            $this->recordOrders($batchId, 0, 0, false, $this->shortReason($e));

            throw $e;
        } finally {
            // Every path, including the provider failure that propagates out of run(). A lock left held
            // would block this store and range until the connection closed.
            $this->lock->release($name);
        }
    }

    /**
     * The lock name for one store and range.
     *
     * One definition, so a second caller cannot pick a different string and defeat the lock by
     * accident. The exact string the web action used before the lock moved in here.
     */
    public static function lockName(int $accountId, OrderDateRange $range): string
    {
        return sprintf('kf-o58-orders-%d-%s-%s', $accountId, $range->from, $range->to);
    }

    /** The run itself, exactly as it was before the lock moved in around it. */
    private function run(int $accountId, OrderDateRange $range, ?int $batchId = null): OrderSyncSummary
    {
        $startedAt = microtime(true);
        $summary = new OrderSyncSummary();

        // A provider failure ends the run before anything is written, so there is never a partial state
        // to explain. The exception's message is already operator-safe and carries no body or header.
        $records = $this->client->listOrders($accountId, $range->from, $range->to);

        $summary->received = count($records);
        if ($records === []) {
            return $summary;
        }

        $now = $this->clock->now();

        foreach (array_chunk($records, self::CHUNK) as $chunk) {
            // Checked here rather than per order: the cost of one more chunk is bounded and known, and
            // abandoning mid-chunk would leave the summary describing a state nobody asked for.
            if (microtime(true) - $startedAt > $this->deadlineSeconds) {
                $summary->stoppedEarly = true;

                break;
            }

            $this->processChunk($accountId, $chunk, $summary, $now, $batchId);
        }

        return $summary;
    }

    /**
     * @param list<array<array-key, mixed>> $chunk
     */
    private function processChunk(
        int $accountId,
        array $chunk,
        OrderSyncSummary $summary,
        \DateTimeImmutable $now,
        ?int $batchId = null,
    ): void {
        $mapped = [];
        foreach ($chunk as $record) {
            try {
                $order = $this->mapper->toOrder($accountId, $record);
            } catch (OrderNotMappable $e) {
                $summary->note($e->getMessage());

                continue;
            }

            // A response that repeats an order is deterministic rather than a race: the later copy wins,
            // because it is the later copy. Both are counted once, which is why this keys by id.
            $mapped[$order->sourceOrderId] = $order;
        }

        if ($mapped === []) {
            return;
        }

        $known = $this->orders->hashesFor($accountId, array_keys($mapped));

        /** @var list<int> $changed */
        $changed = [];
        /** @var list<int> $unchanged */
        $unchanged = [];

        foreach ($mapped as $sourceOrderId => $order) {
            if (array_key_exists($sourceOrderId, $known) && $known[$sourceOrderId] === $order->contentHash) {
                $summary->unchanged++;
                // Collected, not skipped: an unchanged HEADER says nothing about whether this order's
                // items have ever been fetched. See enqueueItems().
                $unchanged[] = $sourceOrderId;

                continue;
            }

            try {
                $created = $this->orders->upsert($order, $now);
            } catch (\Throwable) {
                // Per order, not per chunk. The message is this application's own — a driver exception
                // can carry the parameter values, and those are a customer's address and phone.
                $summary->note('Order ' . $sourceOrderId . ' could not be saved.');

                continue;
            }

            $created ? $summary->created++ : $summary->updated++;
            $changed[] = $sourceOrderId;
        }

        $this->enqueueItems($accountId, $changed, $unchanged, $batchId, $summary->stoppedEarly);
    }

    /**
     * Register item work for every order this chunk saw.
     *
     * ## Why it is here and not beside the upsert
     *
     * The loop above `continue`s past an unchanged order **before** it writes. An enqueue placed with
     * the write would therefore never reach an order whose header had not moved — which is most of
     * them, and all of the historical backlog. So this runs afterwards, over both lists, and the
     * repository decides per order what actually changes: a `PENDING` order is queued whatever its
     * hash did, while a `SYNCED` order with an unchanged parent is left alone so an ordinary sync does
     * not spend one API request per order.
     *
     * Three bounded local statements per 50-order chunk, inside the sync lock, with no network call.
     * A failure here is logged and swallowed: orders were fetched and saved, and losing that to a
     * bookkeeping error would be the worse outcome by far. The next sync re-queues anyway.
     *
     * @param list<int> $changed
     * @param list<int> $unchanged
     */
    private function enqueueItems(
        int $accountId,
        array $changed,
        array $unchanged,
        ?int $batchId,
        bool $stoppedEarly,
    ): void {
        if ($changed === [] && $unchanged === []) {
            return;
        }

        $now = $this->clock->now();

        try {
            $this->itemSyncs->enqueue($accountId, $changed, $unchanged, $this->itemMaxAttempts, $now);
        } catch (\Throwable $e) {
            $this->logger->warning('Order58 item work could not be queued after an order sync.', [
                'reason' => 'order58_item_enqueue_failed',
                'account_id' => $accountId,
                'error_class' => $e::class,
            ]);

            return;
        }

        if ($batchId !== null) {
            $this->enrolChunk($batchId, $accountId, [...$changed, ...$unchanged], $stoppedEarly, $now);
        }
    }

    /**
     * Enrol this chunk's orders in the batch, each according to what the enqueue actually left it as.
     *
     * The statuses are read back rather than inferred, because the enqueue's decision per order depends
     * on state this method cannot see — attempts, retry clocks, whether a snapshot exists. Three cases
     * come out of it, and conflating any two of them breaks the batch:
     *
     *  - `QUEUED` / `PROCESSING` — a request is coming, so the batch waits for it.
     *  - `SYNCED` / `EMPTY` — already current, no request will be made; settled on arrival, because a
     *    batch waiting on a request nobody will send never finishes.
     *  - `FAILED` — past its attempt cap, so nothing will retry it automatically. Settled as failed,
     *    which is what makes the batch honestly `PARTIAL` instead of hanging.
     *
     * One extra bounded read per chunk, no network call, inside the sync lock. A failure here is
     * logged and swallowed: the orders and the queue are already correct, and losing progress
     * bookkeeping is far better than losing the sync.
     *
     * @param list<int> $sourceOrderIds
     */
    private function enrolChunk(
        int $batchId,
        int $accountId,
        array $sourceOrderIds,
        bool $stoppedEarly,
        \DateTimeImmutable $now,
    ): void {
        try {
            $statuses = $this->itemSyncs->statusesFor($accountId, $sourceOrderIds);

            $orders = [];
            $errors = [];

            foreach ($sourceOrderIds as $sourceOrderId) {
                $state = $statuses[$sourceOrderId] ?? ['status' => 'PENDING', 'error' => null];

                $orders[$sourceOrderId] = match ($state['status']) {
                    'QUEUED', 'PROCESSING' => null,
                    'SYNCED', 'EMPTY' => EnrolmentOutcome::Skipped,
                    'FAILED' => EnrolmentOutcome::Failed,
                    // `PENDING` after an enqueue means the enqueue declined it — exhausted attempts, or
                    // a retry not yet due. Either way no request is coming for this batch.
                    default => EnrolmentOutcome::Skipped,
                };

                if ($orders[$sourceOrderId] === EnrolmentOutcome::Failed) {
                    $errors[$sourceOrderId] = $state['error'];
                }
            }

            $this->batches->enrol($batchId, $accountId, $orders, $errors, count($orders), $stoppedEarly, $now);
        } catch (\Throwable $e) {
            $this->logger->warning('Order58 item batch enrolment failed after an order sync.', [
                'reason' => 'order58_item_enrol_failed',
                'account_id' => $accountId,
                'batch_id' => $batchId,
                'error_class' => $e::class,
            ]);
        }
    }

    /** Opens the batch for this operation, or null if progress tracking is unavailable. */
    private function openBatch(int $accountId, OrderDateRange $range, string $triggerSource): ?int
    {
        try {
            return $this->batches->open(
                $accountId,
                $triggerSource,
                $range->from,
                $range->to,
                null,
                $this->clock->now(),
            );
        } catch (\Throwable $e) {
            // A sync must never fail because its progress row could not be written.
            $this->logger->warning('Order58 item sync batch could not be opened.', [
                'reason' => 'order58_item_batch_open_failed',
                'account_id' => $accountId,
                'error_class' => $e::class,
            ]);

            return null;
        }
    }

    private function recordOrders(
        ?int $batchId,
        int $received,
        int $saved,
        bool $truncated,
        ?string $error,
    ): void {
        if ($batchId === null) {
            return;
        }

        try {
            $this->batches->recordOrdersResult($batchId, $received, $saved, $truncated, $error, $this->clock->now());
        } catch (\Throwable $e) {
            $this->logger->warning('Order58 item sync batch could not be updated.', [
                'reason' => 'order58_item_batch_update_failed',
                'batch_id' => $batchId,
                'error_class' => $e::class,
            ]);
        }
    }

    /**
     * A short, operator-safe reason for a failed Orders Sync.
     *
     * The class name and nothing else. A driver or client exception message can carry a URL with a
     * token in it, or the parameter values of a failed write — which are a customer's address.
     */
    private function shortReason(\Throwable $e): string
    {
        return 'The order synchronisation failed (' . $e::class . ').';
    }
}
