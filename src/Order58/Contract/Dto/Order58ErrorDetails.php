<?php

declare(strict_types=1);

namespace App\Order58\Contract\Dto;

/**
 * Normalised, already-redacted description of an Order58 Integration API failure.
 *
 * Mirrors the shape of the AI gateway's error details so the retry policy can be a pure function of it:
 *
 * - {@see $transient} — a temporary condition (network blip, 429, 5xx) a retry might clear.
 * - {@see $sideEffectPossible} — the request may have reached the server. Every Phase-1 Order58 call is a
 *   GET, so this is always false here; it exists so a future write endpoint can be reconciled rather than
 *   blindly resent.
 */
final readonly class Order58ErrorDetails
{
    public function __construct(
        /** Stable machine code, e.g. `auth_failed`, `rate_limited`, `network_error`, `invalid_response`. */
        public string $code,
        /** Human-readable, credential-free message. */
        public string $safeMessage,
        public ?int $httpStatus,
        public bool $transient,
        public bool $sideEffectPossible = false,
        public ?int $retryAfterSeconds = null,
    ) {}

    public static function of(
        string $code,
        string $safeMessage,
        ?int $httpStatus = null,
        bool $transient = false,
        bool $sideEffectPossible = false,
        ?int $retryAfterSeconds = null,
    ): self {
        return new self($code, $safeMessage, $httpStatus, $transient, $sideEffectPossible, $retryAfterSeconds);
    }

    /**
     * Safe context for a log record — never includes anything secret.
     *
     * @return array<string, scalar|null>
     */
    public function toLogContext(): array
    {
        return [
            'error_code' => $this->code,
            'status' => $this->httpStatus,
            'transient' => $this->transient,
        ];
    }
}
