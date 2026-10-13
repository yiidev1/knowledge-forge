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

    /**
     * The two name parts as one line for display, or null when this order names nobody.
     *
     * Null rather than an empty string, so a caller must decide what absence looks like instead of
     * rendering a blank cell that reads as a value. The page shows `-`.
     *
     * Presentation only: {@see $firstName} and {@see $lastName} are still compared separately, because
     * a trainee who enters the forename and omits the surname has made one mistake, not two, and
     * collapsing them here would hide which half differed.
     */
    public function displayName(): ?string
    {
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $name === '' ? null : $name;
    }
}
