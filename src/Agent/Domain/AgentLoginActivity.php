<?php

declare(strict_types=1);

namespace App\Agent\Domain;

use DateTimeImmutable;

/**
 * How one Order58 agent has used Knowledge Forge: when they first signed in, when they last did, and how
 * many successful sign-ins they have made.
 *
 * A usage summary, not an audit record — there is one of these per agent, and it carries no credential,
 * token or `account_id`. The name fields are a snapshot of the authenticated identity, refreshed on each
 * login so the table stays readable without joining the Order58 mirror.
 */
final readonly class AgentLoginActivity
{
    public function __construct(
        public int $agentAdminId,
        public string $username,
        public string $displayName,
        public DateTimeImmutable $firstLoginAt,
        public DateTimeImmutable $lastLoginAt,
        public int $loginCount,
    ) {}

    /**
     * Whether this agent has signed in more than once — the cheap way to tell a one-off visit from real use.
     */
    public function isReturning(): bool
    {
        return $this->loginCount > 1;
    }
}
