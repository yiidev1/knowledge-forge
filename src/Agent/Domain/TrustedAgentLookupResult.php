<?php

declare(strict_types=1);

namespace App\Agent\Domain;

/**
 * The outcome of resolving an entered username to a trusted agent identity.
 *
 * Every rejection collapses to the same generic login failure for the user, but the reasons are kept apart
 * here so an operator can tell "this person is not an agent" from "two agents share that username" from
 * "the mirror is too old to trust" in the logs. Each reason is a fixed code containing no user input.
 */
final readonly class TrustedAgentLookupResult
{
    private function __construct(
        public ?AgentIdentity $agent,
        public string $reason,
    ) {}

    public static function found(AgentIdentity $agent): self
    {
        return new self($agent, 'trusted_agent_resolved');
    }

    /** No active agent carries this username — including the case where the account exists but is not an agent. */
    public static function notFound(): self
    {
        return new self(null, 'no_active_agent');
    }

    /** More than one active agent carries this username. Never guess between them. */
    public static function ambiguous(): self
    {
        return new self(null, 'ambiguous_username');
    }

    /** The row exists but was last seen upstream too long ago to authorize a login. */
    public static function stale(): self
    {
        return new self(null, 'mirror_row_stale');
    }
}
