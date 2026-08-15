<?php

declare(strict_types=1);

namespace App\Order58\Contract\Dto;

/**
 * The outcome of a server-to-server credential check against Order58 `POST /authenticate`.
 *
 * `authenticated` is false for a wrong username/password; a bad Knowledge Forge Bearer token surfaces as an
 * {@see \App\Order58\Contract\Exception\Order58AuthenticationFailed} from the client instead. The password
 * never reaches this object.
 *
 * Note the upstream shape, verified against the live API: `/authenticate` answers a wrong password with
 * **HTTP 401** and `error.code = INVALID_CREDENTIALS` — the same status it uses for a rejected service token
 * (`error.code = UNAUTHORIZED`). {@see \App\Order58\Client\HttpOrder58Client::authenticate()} separates the
 * two on that code. An earlier version of this docblock claimed a wrong password came back as a 200 with
 * `success: false`; it does not, and believing so meant every wrong agent password was reported as a service
 * outage and never charged to the login throttle.
 */
final readonly class Order58AuthResult
{
    private function __construct(
        public bool $authenticated,
        public ?Order58Agent $agent,
    ) {}

    public static function success(Order58Agent $agent): self
    {
        return new self(true, $agent);
    }

    public static function invalid(): self
    {
        return new self(false, null);
    }
}
