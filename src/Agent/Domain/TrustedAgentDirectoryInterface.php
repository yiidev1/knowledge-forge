<?php

declare(strict_types=1);

namespace App\Agent\Domain;

use DateTimeImmutable;

/**
 * Resolves an entered username to a trusted agent identity, for the fallback login path only.
 *
 * The fallback credential API can prove a password is valid but cannot say who the user is, so identity has
 * to come from data Knowledge Forge already trusts. This port exists to keep that lookup — and the rules it
 * enforces — inside the Agent module, which owns what an agent is.
 *
 * Three rules an implementation must honour, all of them load-bearing:
 *
 * 1. **Only an agent.** `user_type = 'agent'` and `status = 'active'`. The validate API happily confirms the
 *    password of an admin or a merchant; admitting one would be a privilege escalation.
 * 2. **Exactly one match.** Usernames are not unique upstream — real collisions exist. Two matches is a
 *    rejection, never a guess.
 * 3. **Fresh enough.** A row last seen upstream before `$notSyncedBefore` is refused, so an agent revoked
 *    upstream cannot keep signing in indefinitely while the sync is broken. A row that has never been synced
 *    is refused too.
 *
 * The primary login path does not use this: it reads `user_type`/`status` live from the Integration API, and
 * can therefore admit an agent the mirror has never seen.
 */
interface TrustedAgentDirectoryInterface
{
    /**
     * @param string            $username        Exactly what the agent typed.
     * @param DateTimeImmutable $notSyncedBefore Oldest `synced_at` still considered trustworthy.
     */
    public function findActiveAgentByUsername(
        string $username,
        DateTimeImmutable $notSyncedBefore,
    ): TrustedAgentLookupResult;
}
