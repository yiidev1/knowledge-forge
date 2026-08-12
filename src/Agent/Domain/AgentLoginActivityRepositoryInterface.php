<?php

declare(strict_types=1);

namespace App\Agent\Domain;

/**
 * Persistence for local agent sign-in activity.
 *
 * Deliberately narrow: it records **successful** logins only, and only as a per-agent summary. Failed
 * attempts belong to {@see \App\Auth\Application\LoginThrottleInterface}, which already owns them and is
 * cleared on success — the two must not be conflated.
 */
interface AgentLoginActivityRepositoryInterface
{
    /**
     * Notes one successful sign-in for this agent, creating the row on first sight and updating it after.
     *
     * `first_login_at` is written once and never again; `last_login_at` moves and `login_count` increments.
     * Must be safe when the same agent signs in twice concurrently — the implementation resolves that in a
     * single statement rather than a read-modify-write.
     *
     * Only the safe fields of {@see AgentIdentity} are persisted. The password, the Order58 bearer token and
     * `account_id` never reach this interface, because they never reach `AgentIdentity` either.
     */
    public function recordSuccessfulLogin(AgentIdentity $identity): void;

    /**
     * One agent's activity, or null when they have never signed in since this table existed.
     */
    public function findByAgent(int $agentAdminId): ?AgentLoginActivity;

    /**
     * Every agent that has ever signed in, most recently active first.
     *
     * @return list<AgentLoginActivity>
     */
    public function findAll(): array;
}
