<?php

declare(strict_types=1);

namespace App\Order58\Application\CaseOrders;

use App\Order58\Domain\CaseOrders\CaseOrder;
use App\Order58\Domain\Items\ItemSyncStateRepositoryInterface;
use App\Order58\Domain\Items\Order58OrderItem;
use App\Order58\Domain\Items\OrderItemRepositoryInterface;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use DateTimeImmutable;
use Throwable;

use function array_map;
use function hash;
use function implode;

/**
 * Case orders and their lines, written into the tables this application already has.
 *
 * ## Reuse, not a parallel world
 *
 * Orders go through the existing order repository and lines through the existing item repository,
 * into `order58_orders` and `order58_order_items`. The endpoint changed; what an order IS did not.
 * A second pair of tables would have meant every screen choosing which one to read.
 *
 * Nothing is re-mapped here either. The parser hands over rows the application's own mappers built,
 * so this class decides only *when* to write and what to report — there is no place in it for a
 * field to be converted, and therefore no place for one to be lost.
 *
 * ## An order is written whole, or not at all
 *
 * The parent and its lines go in one transaction. A half-written order — the parent updated, the
 * lines from the previous import still in place — would be compared against a trainee's work and
 * scored, and nothing on screen would say it was wrong.
 *
 * ## Absence is not deletion
 *
 * The endpoint returns a rolling window of the latest orders. An order that has fallen out of that
 * window is not gone; it is simply not in today's answer. So nothing here deletes an order for
 * being absent, and a failed response changes nothing at all — the day's data is whatever the last
 * good import left, not an empty table.
 *
 * ## One bad order does not stop the rest
 *
 * A response costs a provider request. A row that cannot be written is counted, reported and stepped over, so
 * a single bad order cannot cost the other 199 until tomorrow.
 */
final readonly class CaseOrderImporter
{
    public function __construct(
        private Order58OrderRepositoryInterface $orders,
        private OrderItemRepositoryInterface $items,
        /**
         * The per-order item sync state, settled here as well as the rows themselves.
         *
         * Not bookkeeping: `ItemAvailability` reads `items_status`, and an order left at the column
         * default reports that its items were never fetched. The comparison would then show a
         * complete original order as having no items at all, and blame the trainee for every one of
         * them. Writing the lines and saying they are there are two halves of one fact.
         */
        private ItemSyncStateRepositoryInterface $itemState,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * Write everything the parser could read, and report what happened.
     *
     * Takes the parse result rather than the raw response so the report can include what the parser
     * skipped alongside what the writes skipped: to an operator reading the run, "199 of 200
     * imported" and "which one, and why" are the same question.
     */
    public function import(CaseOrderParseResult $parsed): CaseOrderImportReport
    {
        $report = new CaseOrderImportReport(received: $parsed->orderCount());
        $report->addSkipped($parsed->skipped);

        $now = $this->clock->now();

        /*
         * The stored content hashes, one query per store rather than one per order.
         *
         * An order the provider returns unchanged is the common case on a rolling window — most of
         * the two hundred were already here. Comparing hashes first means an unchanged order costs
         * no write at all: no upsert, no item replacement, and no churn on rows that demo orders,
         * attempts and topics point at.
         *
         * Batched because the alternative is a SELECT per order, which is the N+1 this listing has
         * been careful to avoid everywhere else.
         */
        $known = $this->knownHashes($parsed->orders);

        foreach ($parsed->orders as $order) {
            try {
                $this->one($order, $known, $now, $report);
            } catch (Throwable $e) {
                /*
                 * Counted and stepped over.
                 *
                 * The message is the exception's, which is this application's own text about its own
                 * database — never the provider's payload, so a customer's details cannot reach a
                 * log through here.
                 */
                $report->addFailure(
                    'Order ' . $order->sourceOrderId() . ' could not be saved: ' . $e->getMessage(),
                );

                $this->logger->warning('Case order import failed for one order', [
                    'source_order_id' => $order->sourceOrderId(),
                    'store_source_id' => $order->storeSourceId(),
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return $report;
    }

    /**
     * @param array<string, string> $known stored content hashes, keyed `store:order`
     */
    private function one(
        CaseOrder $order,
        array $known,
        DateTimeImmutable $now,
        CaseOrderImportReport $report,
    ): void {
        $key = $order->storeSourceId() . ':' . $order->sourceOrderId();

        if (($known[$key] ?? null) === $order->order->contentHash) {
            /*
             * Identical to what is stored, so nothing is written.
             *
             * The hash is taken over the mapped fields AND the sanitised payload, so "identical"
             * means the provider sent the same order — not merely that the columns this application
             * reads happen to match.
             */
            ++$report->unchanged;

            return;
        }

        $inserted = $this->orders->upsert($order->order, $now);

        if ($inserted) {
            ++$report->inserted;
        } else {
            ++$report->updated;
        }

        /*
         * Lines replaced wholesale rather than merged.
         *
         * The response is authoritative about what the order contains now: a line the customer had
         * removed is absent from it, and merging would leave that removed line in place forever.
         * Replacement is also what makes the import idempotent — running it twice leaves exactly the
         * same rows.
         *
         * `null` lease: this importer holds the day's single allowance, so it is the only writer by
         * construction. See the repository for why that is stronger than the per-order lease.
         */
        $written = $this->items->replace(
            $order->storeSourceId(),
            $order->sourceOrderId(),
            $order->items,
            null,
            $now,
        );

        if (!$written) {
            $report->addFailure(
                'Order ' . $order->sourceOrderId() . ': its lines could not be written.',
            );

            return;
        }

        /*
         * Only after the lines are actually in. Saying "synced" first and then failing to write them
         * would leave an order claiming a snapshot it does not have, which is the one state the
         * comparison cannot detect — it would compare against nothing and report everything missing.
         *
         * No per-order lease and no per-order response: this import holds the day's single allowance,
         * and one request returned every order. See the interface for both.
         */
        $this->itemState->markSynced(
            $order->storeSourceId(),
            $order->sourceOrderId(),
            null,
            $order->itemCount(),
            $this->itemsHash($order),
            null,
            $now,
        );

        $report->itemsImported += $order->itemCount();
    }

    /**
     * The content hashes already stored for the orders in this response.
     *
     * Grouped by store because that is how the repository asks: `hashesFor()` takes one account and
     * its order ids. Fifty stores is fifty indexed reads, against two hundred otherwise.
     *
     * @param list<CaseOrder> $orders
     *
     * @return array<string, string> keyed `store:order`
     */
    private function knownHashes(array $orders): array
    {
        $byStore = [];

        foreach ($orders as $order) {
            $byStore[$order->storeSourceId()][] = $order->sourceOrderId();
        }

        $known = [];

        foreach ($byStore as $store => $ids) {
            foreach ($this->orders->hashesFor($store, $ids) as $sourceOrderId => $hash) {
                $known[$store . ':' . $sourceOrderId] = $hash;
            }
        }

        return $known;
    }

    /**
     * A change signal for this order's lines, derived from the lines themselves.
     *
     * The legacy integration hashed the per-order items response, which does not exist here — one
     * request answers for every order. Each line already carries a `content_hash` taken over its
     * canonical form *and* its raw record, so hashing those in order answers the same question: has
     * anything about this order's lines changed since the last import.
     *
     * Over the mapped lines rather than the payload, which also means it cannot carry a payment
     * secret: the mapper has already stripped them by the time this runs.
     */
    private function itemsHash(CaseOrder $order): string
    {
        $parts = array_map(
            static fn(Order58OrderItem $item): string => $item->sourceItemId . ':' . $item->contentHash,
            $order->items,
        );

        return hash('sha256', implode("\n", $parts));
    }
}
