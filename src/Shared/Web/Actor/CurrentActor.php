<?php

declare(strict_types=1);

namespace App\Shared\Web\Actor;

use LogicException;

/**
 * The person performing the current request, in the one shape both Order Testing and Audio-to-Text can
 * read without naming each other.
 *
 * ## Why a neutral holder rather than one shared interface
 *
 * Order Testing and A2T are forbidden from naming one another — `ModuleIsolationTest` stops anything outside the
 * audio module from naming it, and `OrderTestingBoundariesTest` stops Order Testing from naming other
 * business modules. Neither may hold the other's abstraction, and nothing outside the audio module may
 * hold that module's.
 *
 * Both modules *may* name the shared namespace, and already do. So this class names no module at all: each side
 * declares its own port and adapts this holder to it. One signed-in person, two ports, zero coupling —
 * which is how both isolation tests stay green without being edited.
 *
 * ## Two id columns, never one
 *
 * `admin_users.id = 7` and Order58's `admin_id = 7` are different people. Keeping them in separate
 * fields, with the realm saying which is populated, is what stops the day-one bug where one realm's work
 * is attributed to the other's user of the same number. Exactly one field is ever set.
 *
 * Operation users and agents both carry Order58's `admin_id`, and they are still kept apart here: they
 * are different realms with different rights, and a shared field would make "which realm acted" a thing
 * the reader has to infer. The id space they share is exactly why the realm travels with the id
 * everywhere it is stored or joined.
 *
 * ## Written once, by middleware that has already proved who this is
 *
 * The authorization middleware sets this after verifying the session — re-loading an administrator from
 * `admin_users`, or re-checking an operation user against the `order58_agents` mirror. Nothing derives
 * it from request input, so a posted actor id changes nothing.
 */
final class CurrentActor
{
    public const REALM_ADMIN = 'admin';
    public const REALM_OPERATION = 'operation';
    public const REALM_AGENT = 'agent';

    private ?string $realm = null;
    private ?int $adminId = null;
    private ?int $operationId = null;
    private ?int $agentId = null;
    private string $displayName = '';

    /** @param int $adminId `admin_users.id` */
    public function setAdmin(int $adminId, string $displayName): void
    {
        $this->realm = self::REALM_ADMIN;
        $this->adminId = $adminId;
        // Cleared rather than left alone: a holder reused within one request must never end up
        // claiming to be two realms at once.
        $this->operationId = null;
        $this->agentId = null;
        $this->displayName = $displayName;
    }

    /**
     * @param int $order58AdminId Order58's own `admin_id` — the identity that survives a mirror rebuild,
     *                            never `order58_agents.id`
     */
    public function setOperation(int $order58AdminId, string $displayName): void
    {
        $this->realm = self::REALM_OPERATION;
        $this->operationId = $order58AdminId;
        $this->adminId = null;
        $this->agentId = null;
        $this->displayName = $displayName;
    }

    /**
     * An Order58 agent, on the one surface that admits them.
     *
     * Written by the practice-orders middleware after the agent gate has already proved the session.
     * The agent area's own middleware does not call this: the rest of that area needs no actor, and a
     * realm that appears only where it is used cannot leak into a page that never expected it.
     *
     * @param int $order58AdminId Order58's own `admin_id`, as the session identity carries it — the
     *                            same value an operation user stores, in a different realm
     */
    public function setAgent(int $order58AdminId, string $displayName): void
    {
        $this->realm = self::REALM_AGENT;
        $this->agentId = $order58AdminId;
        $this->adminId = null;
        $this->operationId = null;
        $this->displayName = $displayName;
    }

    public function isResolved(): bool
    {
        return $this->realm !== null;
    }

    public function isAdmin(): bool
    {
        return $this->realm === self::REALM_ADMIN;
    }

    public function isOperation(): bool
    {
        return $this->realm === self::REALM_OPERATION;
    }

    public function isAgent(): bool
    {
        return $this->realm === self::REALM_AGENT;
    }

    /** `admin_users.id`, or null when an operation user is acting. */
    public function adminId(): ?int
    {
        return $this->adminId;
    }

    /** Order58's `admin_id`, or null when anyone but an operation user is acting. */
    public function operationId(): ?int
    {
        return $this->operationId;
    }

    /** Order58's `admin_id`, or null when anyone but an agent is acting. */
    public function agentId(): ?int
    {
        return $this->agentId;
    }

    public function displayName(): string
    {
        $this->assertResolved();

        return $this->displayName;
    }

    /**
     * The realm string, for an adapter that maps it onto its own vocabulary.
     *
     * Throws rather than returning null, because every caller sits behind middleware that has already
     * populated this. Reaching here unresolved means a route was wired without that gate, which is a
     * programming error worth failing loudly rather than a state to handle.
     */
    public function realm(): string
    {
        // Checked inline rather than through the helper so the narrowing is visible to static analysis;
        // a cast here would hide a genuine null rather than prove it cannot happen.
        if ($this->realm === null) {
            throw self::unresolved();
        }

        return $this->realm;
    }

    private function assertResolved(): void
    {
        if ($this->realm === null) {
            throw self::unresolved();
        }
    }

    private static function unresolved(): LogicException
    {
        return new LogicException(
            'No actor has been resolved for this request. A route that reads the current actor must sit '
            . 'behind the middleware that establishes it.',
        );
    }
}
