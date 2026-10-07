<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

/**
 * The shipping block of an order.
 *
 * `street` is read from `street1`, which is also on the payment-secret list and is therefore stripped
 * from the **stored** payload. The delivery address reaches this object from its own columns on the demo
 * side; see {@see \App\Shared\Order58\PaymentSecretRedactor}.
 */
final readonly class NormalizedAddress
{
    public function __construct(
        public ?string $street = null,
        public ?string $street2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $destination = null,
    ) {}
}
