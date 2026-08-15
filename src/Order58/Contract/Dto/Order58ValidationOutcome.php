<?php

declare(strict_types=1);

namespace App\Order58\Contract\Dto;

/**
 * What the Order58 fallback validate API said about a username/password.
 *
 * Only {@see self::CredentialsRejected} is a statement about the *user's* credentials. Everything else is a
 * statement about the integration — our token, the network, the upstream service or our own configuration —
 * and must never be counted as another wrong password, because doing so would let an outage burn through a
 * legitimate agent's login throttle.
 *
 * The cases are values rather than exceptions: a rejected password is an ordinary outcome on this path, and
 * exceptions would force the caller to catch in order to make a routine decision.
 */
enum Order58ValidationOutcome
{
    /** The body carried an integer `status` of exactly 200. Credentials are valid. */
    case Valid;

    /** The API answered, well-formed, and rejected the credentials. */
    case CredentialsRejected;

    /** *Our* Bearer token was refused (HTTP 401/403) — a configuration problem, not the user's. */
    case AuthFailed;

    /** The API answered with a server error, or a client error we cannot interpret. */
    case UpstreamError;

    /** Unreachable: timeout, DNS, TLS or connection failure. */
    case NetworkError;

    /** Reached, but the body was not usable JSON or lacked an integer `status`. */
    case InvalidResponse;

    /** No URL or no token configured — the fallback is switched off. */
    case NotConfigured;

    /**
     * Whether this outcome is a statement about the credentials rather than about the integration.
     * Everything that is not a credential verdict must resolve to "temporarily unavailable" for the user
     * and must not touch the login throttle.
     */
    public function isCredentialVerdict(): bool
    {
        return $this === self::Valid || $this === self::CredentialsRejected;
    }

    /** The safe, allowlisted reason code for logs. Carries no user input and no secret. */
    public function reason(): string
    {
        return match ($this) {
            self::Valid => 'fallback_credentials_valid',
            self::CredentialsRejected => 'fallback_credentials_rejected',
            self::AuthFailed => 'fallback_auth_failed',
            self::UpstreamError => 'fallback_upstream_error',
            self::NetworkError => 'fallback_network_error',
            self::InvalidResponse => 'fallback_invalid_response',
            self::NotConfigured => 'fallback_not_configured',
        };
    }
}
