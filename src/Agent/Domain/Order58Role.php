<?php

declare(strict_types=1);

namespace App\Agent\Domain;

/**
 * Which kind of Order58 user signed in, and what that entitles them to.
 *
 * ## Why this exists rather than `$userType === 'operation'` scattered about
 *
 * Two realms authenticate through `/agent/login` — agents, who get the agent area, and operation users,
 * who get Order Testing and nothing else. Both arrive as an {@see AgentIdentity} carrying a `user_type`
 * string straight from Order58. Comparing that string at each call site is how one page ends up admitting
 * a role another page refuses; the answer lives here instead, once.
 *
 * ## The vocabulary is Order58's, matched exactly
 *
 * `user_type` is mirrored verbatim from the provider and is **case-sensitive on purpose**: the live data
 * contains `PreTraining` alongside `agent`, `merchant`, `operation`, `trainee`, `trainer`, `admin` and
 * `employee`. Lower-casing before comparing would make `PreTraining` and a future `OPERATION` collide
 * with values this enum deliberately refuses, so {@see fromUserType()} matches the string as sent and
 * returns null for everything it does not recognise.
 *
 * Eligibility is *not* decided here alone. A role says what kind of user this is; the login service also
 * requires `status === 'active'`, and the middleware re-checks both against the local mirror on every
 * request. This enum answers only "which role", never "is this account still good".
 */
enum Order58Role: string
{
    case Agent = 'agent';
    case Operation = 'operation';

    /**
     * The role for an Order58 `user_type`, or null when that type may not sign in at all.
     *
     * Every type absent from this match is refused: `merchant`, `trainee`, `trainer`, `PreTraining`,
     * `admin`, `employee` and anything the provider adds later. A new type is therefore rejected by
     * default and has to be admitted deliberately, which is the safe direction for a list that grows
     * outside this codebase.
     */
    public static function fromUserType(string $userType): ?self
    {
        return match ($userType) {
            self::Agent->value => self::Agent,
            self::Operation->value => self::Operation,
            default => null,
        };
    }

    /**
     * May this role use the Order Testing workflow?
     *
     * Operation users exist for exactly this and have the same functional permissions as an administrator
     * inside it. Agents do not: Order Testing has never had an agent route, and admitting them here would
     * hand the whole workflow to several hundred accounts that were never meant to see it.
     */
    public function canAccessOrderTesting(): bool
    {
        return $this === self::Operation;
    }

    /** May this role use the agent area — store chat, rule chat, the rule list? */
    public function canAccessAgentArea(): bool
    {
        return $this === self::Agent;
    }

    /** Where this role belongs immediately after signing in. A route name, not a URL. */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Agent => 'agent.home',
            // The literal name rather than the route constant: that constant lives inside the audio
            // module, which nothing outside it may name.
            self::Operation => 'order-testing',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Agent => 'Agent',
            self::Operation => 'Operation',
        };
    }
}
