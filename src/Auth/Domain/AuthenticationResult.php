<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * Outcome of an authentication attempt.
 *
 * Failure is intentionally coarse: {@see invalidCredentials()} covers unknown username, wrong password
 * and deactivated account alike, so nothing an attacker sees distinguishes "no such user" from "wrong
 * password". Only the lockout case is separate, because the user genuinely needs to be told to wait.
 */
final readonly class AuthenticationResult
{
    private function __construct(
        private bool $success,
        private ?AdminUser $admin,
        private ?int $retryAfterSeconds,
    ) {}

    public static function success(AdminUser $admin): self
    {
        return new self(true, $admin, null);
    }

    public static function invalidCredentials(): self
    {
        return new self(false, null, null);
    }

    public static function locked(int $retryAfterSeconds): self
    {
        return new self(false, null, $retryAfterSeconds);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isLocked(): bool
    {
        return $this->retryAfterSeconds !== null;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function admin(): ?AdminUser
    {
        return $this->admin;
    }
}
