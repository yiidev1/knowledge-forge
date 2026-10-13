<?php

declare(strict_types=1);

namespace App\Agent\Domain;

/**
 * What an already-signed-in Order58 user is entitled to **right now**.
 *
 * ## Why this is not {@see TrustedAgentDirectoryInterface}
 *
 * That one answers a question about *authentication*: "the weaker API says this password is right — who,
 * if anyone, does this username belong to?" It is keyed on the typed username, rejects ambiguity, and
 * refuses a mirror older than the configured window, because a password verdict without an identity is
 * worth nothing.
 *
 * This answers a question about *authorization*: "the person in this session was already identified —
 * are they still allowed in?" It is keyed on Order58's `admin_id`, which the session carries and which
 * is UNIQUE in the mirror, so ambiguity cannot arise and no username is involved.
 *
 * Keeping them apart matters. Reusing the username lookup here would reintroduce the ambiguity problem
 * on every request for the 136 usernames the mirror duplicates, and would log out a user whose role is
 * perfectly current.
 *
 * ## Staleness is deliberately not checked
 *
 * The 72-hour freshness rule exists to decide whether a *mirror* may stand in for the provider when
 * establishing who someone is. Once a session exists, a stale mirror is a reason to trust the last known
 * role, not a reason to throw a working user out mid-task — the sync runs daily and an outage would
 * otherwise sign everyone out. Revocation still takes effect on the next sync, which is the same promise
 * the rest of the mirror makes.
 */
interface AgentEligibilityReaderInterface
{
    /**
     * The role this user may act as now, or **null** when they may not act at all.
     *
     * Null covers every refusal with one answer on purpose — no mirror row, a status that is not
     * `active`, or a `user_type` that is not an admitted role. A caller cannot accidentally treat
     * "disabled" as different from "unknown", and new provider roles are refused by default.
     *
     * @param int $order58AdminId Order58's own `admin_id`, never `order58_agents.id`
     */
    public function currentRoleFor(int $order58AdminId): ?Order58Role;
}
