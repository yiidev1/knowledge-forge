<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

/**
 * Which realm the person who started a test belongs to.
 *
 * **Only `ADMIN` exists today, and that is the whole list** — the database CHECK admits nothing else, so
 * a row claiming another realm cannot be written even by a bug. Agents and any future operation role are
 * explicitly out of scope: this feature has no agent route, no agent authentication and no agent UI.
 *
 * It is an enum with one case rather than an absent column because the realms number their users
 * separately. `admin_users.id = 7` and a future `order58_agents.id = 7` are different people, and a
 * schema that recorded only the id would attribute one's work to the other on the day the second realm
 * is allowed in. Widening is then one ALTER and one case; narrowing a wrong attribution later is not
 * possible at all.
 */
enum InitiatorType: string
{
    case Admin = 'ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
        };
    }
}
