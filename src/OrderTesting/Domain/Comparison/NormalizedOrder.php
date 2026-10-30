<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

use function str_contains;
use function strtolower;

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
    /** Printed for a card payment whose last four digits were not kept. */
    public const CARD_NOT_RECORDED = 'Card not recorded';

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
        /**
         * The card's last four digits, as kept when the order was stored — or null when none were.
         *
         * Compared and scored only when both orders kept them. Never the card number: nothing else about
         * the card number is stored. See `App\Shared\Order58\PaymentSecretRedactor::cardLastFour()`.
         */
        public ?string $cardLastFour = null,
        /**
         * The card's expiry as the stored order kept it, `MM/YYYY`, or null when none was.
         *
         * Compared and scored only when both orders kept one — like {@see $cardLastFour} beside it.
         * Cardholder data, not authentication data: the verification code is never stored and has no
         * field here.
         */
        public ?string $expiryDate = null,
        /**
         * The billing postcode the stored order kept, or null.
         *
         * Null is the ordinary case: the provider leaves it empty on most card orders.
         */
        public ?string $billingZip = null,
        /** The store's time zone as the order states it, for printing the order's times. */
        public ?string $timezone = null,
    ) {}

    /** Whether this order was paid by card, however the provider spelled the method. */
    public function paidByCard(): bool
    {
        return $this->paymentMethod !== null && str_contains(strtolower($this->paymentMethod), 'card');
    }

    /**
     * The card as it may be printed: `•••• 1234`, {@see CARD_NOT_RECORDED}, or null when the order was
     * not paid by card. One answer for the comparison, the score and both pages.
     */
    public function cardLabel(): ?string
    {
        if ($this->cardLastFour !== null) {
            return '•••• ' . $this->cardLastFour;
        }

        return $this->paidByCard() ? self::CARD_NOT_RECORDED : null;
    }
}
