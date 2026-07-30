<?php

declare(strict_types=1);

namespace App\Auth\Application;

/**
 * Ends the current administrator session.
 *
 * Trivial today, but kept as a service so that later concerns — audit logging, "sign out everywhere",
 * revoking a remember-me token — attach here rather than in a controller action.
 */
final readonly class LogoutService
{
    public function __construct(
        private AdminIdentityStoreInterface $identityStore,
    ) {}

    public function logout(): void
    {
        $this->identityStore->clear();
    }
}
