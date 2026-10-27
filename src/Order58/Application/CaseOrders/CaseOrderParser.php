<?php

declare(strict_types=1);

namespace App\Order58\Application\CaseOrders;

use App\Order58\Application\CallRecordings\CallListParser;
use App\Order58\Application\Items\OrderItemMapper;
use App\Order58\Application\Orders\OrderMapper;
use App\Order58\Domain\CaseOrders\CaseOrder;
use App\Order58\Domain\Items\Order58OrderItem;
use App\Order58\Domain\Items\OrderItemNotMappable;
use App\Shared\Order58\PaymentSecretRedactor;

use function is_array;
use function is_numeric;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The Case Order response, turned into orders and lines this application can store.
 *
 * ## What this owns, and what it deliberately does not
 *
 * It owns three things: the envelope, the decision about whether an entry is usable at all, and the
 * fact that the lines now arrive *inside* the order rather than from a second request.
 *
 * It does **not** own what an order or a line IS. The order half goes to {@see OrderMapper} and the
 * line half to {@see OrderItemMapper} — the two mappers this application already stores these tables
 * with. So there is one definition of which fields are money, which are timestamps, which identify a
 * row, which must be redacted, and how `comparison_json` and `content_hash` are derived.
 *
 * That last point is the one that matters most. `comparison_json` is built by
 * {@see \App\Shared\Order58\Items\ItemCanonicalizer}, which is the *same* interpretation the demo side
 * uses — it already flattens `data.product`, `data.additionals` and `data.combinations`, which is
 * exactly the nested shape this endpoint sends. A second mapping here would have been a second answer
 * to "what did the customer order", and the comparison would have reported the difference between the
 * two mappings as something the trainee got wrong.
 *
 * It is also the answer to "what happens when the provider changes the API": an order-level change
 * lands in `OrderMapper`, a line-level change in `OrderItemMapper`, the envelope here, and nothing
 * downstream moves in any of those cases.
 *
 * ## Redaction happens before either mapper sees a line
 *
 * The live documents carry `card_num`, `exp_date` and a CVV at the top of the order's `data` and again
 * inside `data.cc`. `OrderMapper` strips the order's copy by the same list; each line is redacted here
 * as a record in its own right, which is what the per-order items client did for the endpoint this
 * replaces, before that client was removed in Stage 7a.
 *
 * A line's `data` arrives as a nested object here, where the old endpoint sent a JSON-encoded string.
 * It is encoded back to a string before mapping, so a case order's stored row is the same shape as
 * every row already in the table — the `data` column keeps its faithful copy, and `content_hash` is
 * computed over the same bytes either integration wrote.
 *
 * ## One bad ORDER never costs the other 199 — and one bad LINE never costs its order's other lines
 *
 * A response costs a provider request, so discarding a whole one over a single malformed order means
 * waiting until tomorrow: an unreadable entry is skipped and reported, and the rest import.
 *
 * The granularity stops at the order, though, and deliberately. Lines are written with
 * {@see \App\Order58\Domain\Items\OrderItemRepositoryInterface::replace()}, which replaces an order's
 * whole set — so importing four of five lines would silently *delete* the fifth from an order that
 * still has it, and nothing on screen would say so. An unmappable line therefore fails its own order,
 * which keeps that order's previous import intact, and is reported.
 */
final readonly class CaseOrderParser
{
    public function __construct(
        private OrderMapper $orders = new OrderMapper(),
        private OrderItemMapper $items = new OrderItemMapper(),
        private CallListParser $calls = new CallListParser(),
    ) {}

    /**
     * Parse a decoded response into orders, collecting what could not be read.
     *
     * @param mixed $decoded whatever `json_decode` produced, in whatever shape it arrived
     */
    public function parse(mixed $decoded): CaseOrderParseResult
    {
        /*
         * The provider sends a bare array. An envelope is tolerated anyway — `{"data": [...]}` is
         * the commonest way an API grows one — because discovering that on the day it changed would
         * cost the day's allowance to re-read.
         */
        if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = $decoded['data'];
        }

        if (!is_array($decoded)) {
            return CaseOrderParseResult::unreadable('The response was not a JSON array of orders.');
        }

        $orders = [];
        $skipped = [];

        foreach ($decoded as $index => $raw) {
            if (!is_array($raw)) {
                $skipped[] = 'Entry ' . (string) $index . ' was not an object.';

                continue;
            }

            $order = $this->order($raw, $skipped);

            if ($order !== null) {
                $orders[] = $order;
            }
        }

        return new CaseOrderParseResult($orders, $skipped);
    }

    /**
     * @param array<array-key, mixed> $raw
     * @param list<string>            $skipped collected by reference, so one bad order is a line in
     *                                         a report rather than an exception
     */
    private function order(array $raw, array &$skipped): ?CaseOrder
    {
        $sourceOrderId = $this->int($raw['id'] ?? null);

        /*
         * The store is the ORDER's `account_id`, verified against `order58_stores.source_id`.
         *
         * Never `items[].account_id`, which is `0` in the provider's own sample and identifies
         * nothing — taking it would file every order under a store that does not exist.
         */
        $storeSourceId = $this->int($raw['account_id'] ?? null);

        if ($sourceOrderId === null || $sourceOrderId <= 0) {
            $skipped[] = 'An order arrived with no usable id.';

            return null;
        }

        if ($storeSourceId === null || $storeSourceId <= 0) {
            $skipped[] = 'Order ' . $sourceOrderId . ' names no store (account_id).';

            return null;
        }

        try {
            $items = $this->items($storeSourceId, $sourceOrderId, $raw);
        } catch (OrderItemNotMappable $e) {
            // The whole order, not the one line. See the class docblock: a partial set would delete.
            $skipped[] = 'Order ' . $sourceOrderId . ' was not imported: ' . $e->getMessage() . '.';

            return null;
        }

        /*
         * The call sessions and WAV links, read beside the order rather than into it.
         *
         * `calls` stays in the payload the order mapper keeps, exactly as before; this only picks the
         * links out so the recording catalog can store them. A link that is not a recording URL is
         * dropped and counted — never a reason to skip the order it arrived on.
         */
        $calls = $this->calls->parse($raw['calls'] ?? null);

        // The order half, by the application's own mapper — redaction, money and hash included.
        return new CaseOrder(
            $this->orders->toOrder($storeSourceId, $raw),
            $items,
            $calls->calls,
            $calls->rejectedLinks,
        );
    }

    /**
     * The order's lines, by the mapper that already writes this table.
     *
     * Absent, null and `[]` all mean an order with no lines, which is legitimate and not a malformed
     * order — the endpoint is expected to return orders that have none.
     *
     * `position` is the provider's own ordering, taken as the index into its list, exactly as the
     * endpoint this replaces did. It is what the comparison lists the lines in.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return list<Order58OrderItem>
     *
     * @throws OrderItemNotMappable when any one line cannot be mapped
     */
    private function items(int $storeSourceId, int $sourceOrderId, array $raw): array
    {
        $lines = $raw['items'] ?? null;

        if (!is_array($lines)) {
            return [];
        }

        $items = [];
        $position = 0;

        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new OrderItemNotMappable('one of its lines was not an object');
            }

            /*
             * The mapper checks the line's own `order_id` against the order it arrived inside and
             * refuses a disagreement, which is the check that stops one order's product being filed
             * under another's. It is not repeated here: it is the same rule, in one place.
             */
            $items[] = $this->items->toItem(
                $storeSourceId,
                $sourceOrderId,
                $this->storable($line),
                $position,
            );

            ++$position;
        }

        return $items;
    }

    /**
     * One line, redacted, and in the shape the table already holds.
     *
     * Two things happen, and both are about matching what is already stored rather than about this
     * endpoint:
     *
     * 1. The line is redacted as a record in its own right — the same call the old item client made,
     *    for the same reason: the redactor strips the payment keys at every depth of the record it is
     *    given, so handing it the whole line reaches a secret wherever in the line it sits.
     * 2. A `data` that arrived as a nested object is encoded back to a JSON string. The column is a
     *    string, the old endpoint sent a string, and the canonicaliser reads either — so encoding
     *    here keeps one stored shape instead of two, and keeps `content_hash` comparable across the
     *    integration change.
     *
     * @param array<array-key, mixed> $line
     *
     * @return array<array-key, mixed>
     */
    private function storable(array $line): array
    {
        $line = PaymentSecretRedactor::redact($line);

        $data = $line['data'] ?? null;

        if (is_array($data)) {
            $line['data'] = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        return $line;
    }

    /** An integer from whatever the provider sent — int, numeric string, or nothing. */
    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
