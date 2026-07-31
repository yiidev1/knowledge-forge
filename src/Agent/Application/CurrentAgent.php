<?php

declare(strict_types=1);

namespace App\Agent\Application;

use App\Agent\Domain\AgentIdentity;
use LogicException;

/**
 * The agent for the current request, populated once by {@see \App\Agent\Web\Middleware\RequireAgentMiddleware}.
 * Container-shared but filled fresh per request under PHP-FPM, mirroring `CurrentAdmin`.
 */
final class CurrentAgent
{
    private ?AgentIdentity $agent = null;

    public function set(AgentIdentity $agent): void
    {
        $this->agent = $agent;
    }

    public function isAuthenticated(): bool
    {
        return $this->agent !== null;
    }

    public function get(): AgentIdentity
    {
        if ($this->agent === null) {
            throw new LogicException('No agent is authenticated for this request.');
        }

        return $this->agent;
    }

    public function getOrNull(): ?AgentIdentity
    {
        return $this->agent;
    }
}
