<?php

declare(strict_types=1);

namespace App\Agent\Domain;

/**
 * The outcome of an agent login attempt. `invalidCredentials` deliberately covers a wrong password, an
 * unknown user, a disabled account and a non-agent `user_type` alike, so the response never reveals which
 * — no account enumeration. `unavailable` is a service problem (a bad Bearer token or a transport failure)
 * and does not count against the login throttle.
 */
final readonly class AgentAuthenticationResult
{
    private function __construct(
        public bool $success,
        public bool $locked,
        public bool $unavailable,
        public ?int $retryAfterSeconds,
        public ?AgentIdentity $agent,
        /**
         * An already-sanitized message to show instead of the generic "temporarily unavailable" wording.
         * Only ever set alongside `unavailable`, and only when an upstream service supplied something worth
         * showing. A credential verdict never carries one — a wrong password always uses the application's
         * own generic message, so provider wording can never leak into that path.
         */
        public ?string $message = null,
    ) {}

    public static function success(AgentIdentity $agent): self
    {
        return new self(true, false, false, null, $agent);
    }

    public static function invalidCredentials(): self
    {
        return new self(false, false, false, null, null);
    }

    public static function locked(int $retryAfterSeconds): self
    {
        return new self(false, true, false, $retryAfterSeconds, null);
    }

    public static function unavailable(): self
    {
        return new self(false, false, true, null, null);
    }

    /** As {@see self::unavailable()}, but shows the supplied message in place of the generic wording. */
    public static function unavailableWithMessage(?string $message): self
    {
        return $message === null || $message === ''
            ? self::unavailable()
            : new self(false, false, true, null, null, $message);
    }
}
