<?php

declare(strict_types=1);

namespace App\Agent\Domain;

/**
 * Where the signed-in agent's safe identity lives between requests. A separate realm from the admin
 * identity store: a distinct session key, so an admin and an agent session never collide.
 */
interface AgentIdentityStoreInterface
{
    /**
     * Persists the identity and regenerates the session id (anti session-fixation).
     */
    public function store(AgentIdentity $identity): void;

    public function current(): ?AgentIdentity;

    public function clear(): void;
}
