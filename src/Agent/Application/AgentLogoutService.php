<?php

declare(strict_types=1);

namespace App\Agent\Application;

use App\Agent\Domain\AgentIdentityStoreInterface;

/**
 * Ends an agent's session. Clears only the agent identity key, so a co-existing admin session is untouched.
 */
final readonly class AgentLogoutService
{
    public function __construct(
        private AgentIdentityStoreInterface $identityStore,
    ) {}

    public function logout(): void
    {
        $this->identityStore->clear();
    }
}
