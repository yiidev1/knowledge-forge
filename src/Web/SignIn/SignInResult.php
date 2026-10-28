<?php

declare(strict_types=1);

namespace App\Web\SignIn;

/**
 * How one press of the shared Sign in button ended, in the terms the login page needs.
 *
 * Success carries only where the person goes next — the route of the area their verified account
 * belongs to. Which realm that was is already in the session; the page has no further use for it.
 */
final readonly class SignInResult
{
    private function __construct(
        /** The verified account's own home: the dashboard, the agent app or Order Testing. */
        public ?string $homeRoute,
        public ?int $retryAfterSeconds,
        public bool $unavailable,
        /** An upstream sign-in service's own sentence, already sanitized; null for the generic one. */
        public ?string $message = null,
    ) {}

    public static function signedIn(string $homeRoute): self
    {
        return new self($homeRoute, null, false);
    }

    public static function invalid(): self
    {
        return new self(null, null, false);
    }

    public static function locked(int $retryAfterSeconds): self
    {
        return new self(null, $retryAfterSeconds, false);
    }

    public static function unavailable(?string $message): self
    {
        return new self(null, null, true, $message);
    }

    public function isSuccess(): bool
    {
        return $this->homeRoute !== null;
    }

    public function isLocked(): bool
    {
        return $this->retryAfterSeconds !== null;
    }
}
