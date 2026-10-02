<?php

declare(strict_types=1);

namespace App\Order58\Application\Orders;

use App\Order58\Client\Orders\OrderDataClientInterface;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;

use function array_chunk;
use function array_key_exists;
use function microtime;

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
        private int $deadlineSeconds,
    ) {}

    public function sync(int $accountId, OrderDateRange $range): OrderSyncSummary
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

            $this->processChunk($accountId, $chunk, $summary, $now);
        }

        return $summary;
    }

    /**
     * @param list<array<array-key, mixed>> $chunk
     */
    private function processChunk(int $accountId, array $chunk, OrderSyncSummary $summary, \DateTimeImmutable $now): void
    {
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

        foreach ($mapped as $sourceOrderId => $order) {
            if (array_key_exists($sourceOrderId, $known) && $known[$sourceOrderId] === $order->contentHash) {
                $summary->unchanged++;

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
        }
    }
}
