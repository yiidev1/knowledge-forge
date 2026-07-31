<?php

declare(strict_types=1);

namespace App\Order58\Contract\Dto;

/**
 * The outcome of a server-to-server credential check against Order58 `POST /authenticate`.
 *
 * `authenticated` is false for a wrong username/password (a normal 200 with `success: false`); a bad
 * Knowledge Forge Bearer token surfaces as an {@see \App\Order58\Contract\Exception\Order58AuthenticationFailed}
 * from the client instead. The password never reaches this object.
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
