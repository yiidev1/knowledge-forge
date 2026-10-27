<?php

declare(strict_types=1);

namespace App\Order58\Application\CallRecordings;

use App\Order58\Domain\CallRecordings\CallRecordingRepositoryInterface;
use App\Order58\Domain\CallRecordings\NewRecording;
use App\Order58\Domain\CallRecordings\OrderCalls;
use App\Order58\Domain\CallRecordings\OrderRecordingChanges;
use App\Order58\Domain\CallRecordings\StoredCallSession;
use App\Order58\Domain\CallRecordings\StoredRecording;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_chunk;
use function array_values;
use function count;

/**
 * Keeps the stored call sessions and recording links in step with what the provider lists.
 *
 * ## It runs for every order, changed or not
 *
 * The Case Order import skips an order whose content hash is unchanged, which is right for orders and
 * lines and wrong for this: an order synchronised before this table existed — or before its recording
 * was listed — would otherwise never gain one, because its hash would never move again. So this
 * compares each order's listing with the stored rows on its own terms, and that comparison *is* the
 * change detection. An order whose links are already stored costs no write at all.
 *
 * ## The rules, which are the whole of the design
 *
 * - **A new session or link is added.** Identity is the session id and the SHA-256 of the exact URL,
 *   so the same response twice writes nothing and a repeated link is one row.
 * - **Within a session that is listed with links, a stored link that is not among them is marked
 *   replaced** (`delisted_at`). That is how "the provider updated the URL" is told apart from "the
 *   provider said nothing": a replacement names what replaced it.
 * - **Silence removes nothing.** No `calls`, a `null`, an empty list, or a session missing from a later
 *   response leave every stored row exactly as it was. Rows go only when their order is deleted.
 * - **A link that comes back is current again**, and a reordered list moves positions in place.
 *
 * ## One order never costs another
 *
 * Each order is written in its own transaction and a failure is counted, logged without its URLs, and
 * stepped over. It never fails the synchronisation that called it: orders and lines are already stored
 * by then, and a recording is not a reason to throw them away.
 */
final readonly class CallRecordingCatalog
{
    /** Orders read per batch: two queries each, whatever the batch holds. */
    private const BATCH = 200;

    public function __construct(
        private CallRecordingRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<OrderCalls> $orders
     * @param bool             $write  false works everything out and writes nothing — the backfill's dry run
     */
    public function record(array $orders, DateTimeImmutable $now, bool $write = true): CallRecordingReport
    {
        $report = new CallRecordingReport();

        foreach (array_chunk($orders, self::BATCH) as $batch) {
            $this->batch($batch, $now, $write, $report);
        }

        return $report;
    }

    /**
     * @param list<OrderCalls> $orders
     */
    private function batch(array $orders, DateTimeImmutable $now, bool $write, CallRecordingReport $report): void
    {
        $relevant = [];

        foreach ($orders as $order) {
            ++$report->ordersSeen;
            $report->linksRejected += $order->rejectedLinks;

            if ($order->hasLinks()) {
                $relevant[] = $order;
            }
        }

        if ($relevant === []) {
            return;
        }

        $storedOrders = $this->repository->storedOrders($relevant);
        $sessions = $this->repository->sessionsFor($relevant);

        foreach ($relevant as $order) {
            if (!isset($storedOrders[$order->key()])) {
                // The order itself could not be imported, so there is nothing to attach a call to.
                ++$report->ordersMissing;

                continue;
            }

            $changes = $this->changesFor($order, $sessions[$order->key()] ?? [], $report);

            if ($changes->isEmpty()) {
                ++$report->ordersUnchanged;

                continue;
            }

            ++$report->ordersChanged;

            if (!$write) {
                continue;
            }

            try {
                $this->repository->apply($order->storeSourceId, $order->sourceOrderId, $changes, $now);
            } catch (Throwable $e) {
                ++$report->ordersFailed;

                // Ids and the exception class only. The URLs carry a customer's phone number, and a
                // driver message would quote them back as parameters.
                $this->logger->warning('Case Order call recordings could not be saved for one order.', [
                    'reason' => 'order58_call_recordings_write_failed',
                    'store_source_id' => $order->storeSourceId,
                    'source_order_id' => $order->sourceOrderId,
                    'error_class' => $e::class,
                ]);
            }
        }
    }

    /**
     * @param array<string, StoredCallSession> $stored keyed by provider session id
     */
    private function changesFor(OrderCalls $order, array $stored, CallRecordingReport $report): OrderRecordingChanges
    {
        $changes = new OrderRecordingChanges();

        foreach ($order->calls as $call) {
            // An empty list says nothing about the session's files, so it changes nothing.
            if ($call->urls === []) {
                continue;
            }

            $session = $stored[$call->sessionId] ?? null;
            $urls = $this->distinct($call->urls);

            if ($session === null) {
                $recordings = [];

                foreach ($urls as $position => $url) {
                    $recordings[] = new NewRecording($position, $url);
                }

                $changes->newSessions[$call->sessionId] = $recordings;
                ++$report->sessionsAdded;
                $report->recordingsAdded += count($recordings);

                continue;
            }

            $seen = [];

            foreach ($urls as $position => $url) {
                $hash = NewRecording::hashOf($url);
                $seen[$hash] = true;
                $existing = $session->recordings[$hash] ?? null;

                if ($existing === null) {
                    $changes->newRecordings[$session->rowId][] = new NewRecording($position, $url);
                    ++$report->recordingsAdded;

                    continue;
                }

                if (!$existing->listed) {
                    ++$report->recordingsRelisted;
                } elseif ($existing->position === $position) {
                    continue;
                }

                $changes->updates[$existing->rowId] = new StoredRecording($existing->rowId, $position, true);
            }

            // Listed with links, and these were not among them: replaced, not deleted.
            foreach ($session->recordings as $hash => $existing) {
                if ($existing->listed && !isset($seen[$hash])) {
                    $changes->updates[$existing->rowId] = new StoredRecording(
                        $existing->rowId,
                        $existing->position,
                        false,
                    );
                    ++$report->recordingsReplaced;
                }
            }
        }

        return $changes;
    }

    /**
     * The links once each, in listing order, positions renumbered.
     *
     * The parser already removes repeats; this holds the rule here as well, because the unique key would
     * otherwise turn a repeated link from any other caller into a failed order.
     *
     * @param list<string> $urls
     *
     * @return list<string>
     */
    private function distinct(array $urls): array
    {
        $distinct = [];

        foreach ($urls as $url) {
            $distinct[NewRecording::hashOf($url)] ??= $url;
        }

        return array_values($distinct);
    }
}
