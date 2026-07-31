<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Agent;

use App\Agent\Domain\AgentIdentity;
use App\Agent\Domain\AgentIdentityStoreInterface;

/**
 * In-memory agent identity store for unit tests. Records what was stored so a test can inspect exactly the
 * (safe) fields that reached the session.
 */
final class InMemoryAgentIdentityStore implements AgentIdentityStoreInterface
{
    public ?AgentIdentity $stored = null;
    public int $storeCalls = 0;
    public int $clearCalls = 0;

    public function store(AgentIdentity $identity): void
    {
        $this->stored = $identity;
        $this->storeCalls++;
    }

    public function current(): ?AgentIdentity
    {
        return $this->stored;
    }

    public function clear(): void
    {
        $this->stored = null;
        $this->clearCalls++;
    }
}
