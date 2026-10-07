<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

/**
 * One Order58 order, read into the one shape both sides of the comparison use.
 *
 * ## Why there is only one of these
 *
 * A demo order document and a mirrored source order are **the same kind of document**. The listener that
 * writes a demo file writes the order object Order58 emits; the orders mirror stores the order object
 * Order58's API returns. Giving each side its own reader would have produced two parsers of one format,
 * and the first field they disagreed about would have shown up as a difference the trainee did not make.
 *
 * So there is one normalizer, applied to `order58_orders.payload_json` on the left and to
 * `order_testing_demo_orders.raw_payload` on the right. A parsing bug can then only ever affect both
 * sides identically, which is the one kind of bug a comparison survives.
 *
 * ## Nothing downstream touches raw JSON
 *
 * The comparison service and the page consume this object and never a payload. That is what lets scoring
 * be added later as a reader of {@see OrderComparison} rather than as a second parser.
 */
final readonly class NormalizedOrder
{
    /** @param list<NormalizedItem> $items */
    public function __construct(
        /** The order's own id as the document states it. Shown, never used to pair anything. */
        public ?int $orderId,
        public NormalizedCustomer $customer,
        public ?string $orderType,
        public ?string $paymentMethod,
        public ?string $status,
        public NormalizedAddress $address,
        public NormalizedTotals $totals,
        public array $items,
        /** The provider's clock, Unix seconds. */
        public ?int $createdAt = null,
        public ?int $updatedAt = null,
    ) {}
}
