<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

/**
 * The money on an order. Every field is a **string**, all the way to the page.
 *
 * The provider sends five-decimal strings and a float would make the totals that are the entire point of
 * comparing two orders subtly wrong. Equality is decided on the canonical decimal value, so `"4.28000"`
 * and `"4.28"` are the same amount — see {@see \App\OrderTesting\Application\Comparison\ComparisonRules}.
 */
final readonly class NormalizedTotals
{
    public function __construct(
        public ?string $subtotal = null,
        public ?string $shippingFee = null,
        public ?string $tax = null,
        public ?string $surcharge = null,
        public ?string $tip = null,
        public ?string $discount = null,
        public ?string $total = null,
    ) {}
}
