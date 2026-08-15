<?php

declare(strict_types=1);

namespace App\Order58\Contract\Dto;

/**
 * The outcome of a fallback credential check, plus at most one already-sanitized message.
 *
 * This is deliberately the ONLY thing that leaves the validator. The raw upstream payload never travels:
 * no `name`, no `code`, no `status`, no JSON, and in particular no `account_id` — which is employer data
 * and is never read on this path. A caller therefore cannot accidentally surface a field that was never
 * meant to be shown.
 *
 * `$safeMessage` is non-null only when the upstream sent something worth showing a user AND it survived
 * sanitisation (a non-empty string, control characters collapsed, trimmed, length-capped). It is still
 * escaped at render time; sanitisation here is about shape and size, not about trusting the content.
 */
final readonly class Order58ValidationResult
{
    private function __construct(
        public Order58ValidationOutcome $outcome,
        public ?string $safeMessage,
    ) {}

    public static function of(Order58ValidationOutcome $outcome, ?string $safeMessage = null): self
    {
        // A credential verdict speaks for itself in the application's own words; the upstream's wording is
        // never used for it, so a wrong password can never surface provider text.
        return new self($outcome, $outcome->isCredentialVerdict() ? null : $safeMessage);
    }

    public function isValid(): bool
    {
        return $this->outcome === Order58ValidationOutcome::Valid;
    }
}
