<?php

declare(strict_types=1);

namespace App\Ai\Contract\Dto;

/**
 * Normalised, provider-neutral description of an AI failure.
 *
 * The two flags encode the decisions the rest of the system needs to make without re-inspecting HTTP:
 *
 * - {@see $transient} — a temporary condition (rate limit, 5xx, network blip) that a retry might clear.
 * - {@see $sideEffectPossible} — the request may have reached the server and had an effect. This is the
 *   difference between "safe to blindly retry" and "must reconcile before retrying", and it is why a
 *   non-idempotent create that read-times-out is never simply resent.
 *
 * Every message here is already redacted and safe to log or persist.
 */
final readonly class AiErrorDetails
{
    public function __construct(
        /** Stable machine code, e.g. `rate_limited`, `timeout`, `auth_failed`, `invalid_response`. */
        public string $code,
        /** Human-readable, credential-free message. */
        public string $safeMessage,
        public ?int $httpStatus,
        /** OpenAI's `x-request-id`, invaluable when raising a support ticket. */
        public ?string $requestId,
        public bool $transient,
        public bool $sideEffectPossible,
        public ?int $retryAfterSeconds = null,
    ) {}

    public static function of(
        string $code,
        string $safeMessage,
        ?int $httpStatus = null,
        ?string $requestId = null,
        bool $transient = false,
        bool $sideEffectPossible = false,
        ?int $retryAfterSeconds = null,
    ): self {
        return new self($code, $safeMessage, $httpStatus, $requestId, $transient, $sideEffectPossible, $retryAfterSeconds);
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
            'openai_request_id' => $this->requestId,
            'transient' => $this->transient,
            'side_effect_possible' => $this->sideEffectPossible,
        ];
    }
}
