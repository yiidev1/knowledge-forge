<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

/** The reservation block of an order, in the one shape both sides are read into. */
final readonly class NormalizedCustomer
{
    public function __construct(
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?int $customerCount = null,
        public ?string $instructions = null,
    ) {}
}
