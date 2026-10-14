<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

/**
 * Which realm the person who started a test belongs to.
 *
 * **`ADMIN`, `OPERATION` and `AGENT`, and that is the whole list** — the database CHECK admits nothing
 * else, so a row claiming another realm cannot be written even by a bug.
 *
 * `AGENT` was added when the practice surface opened to Order58 agents. It is reachable from exactly
 * one place: the agent Practice Orders group, whose middleware resolves the actor. Order Testing itself
 * still has no agent route, and the role policy in the signing-in realm still refuses agents there.
 *
 * ## Why the realm is stored at all
 *
 * The realms number their users separately. `admin_users.id = 7` and Order58's `admin_id = 7` are
 * different people, and a schema that recorded only the id would attribute one's work to the other. The
 * pair is what makes an attempt traceable to an actual person.
 *
 * `OPERATION` **and `AGENT`** ids are both Order58's own `admin_id` — the value that survives a rebuild
 * of the `order58_agents` mirror — never `order58_agents.id`, which is a local key. The two realms
 * share an id space and are told apart by this column, which is precisely why every join that resolves
 * a name is conditioned on it as well as on the id.
 *
 * ## The spelling
 *
 * Upper case here, lower case in `audio_segment_revisions.edited_by_type` and the other audio actor
 * columns. Both predate this enum and each is correct in its own table; the two are translated between
 * only in the adapters that read the request's resolved actor, so neither spelling leaks.
 */
enum InitiatorType: string
{
    case Admin = 'ADMIN';
    case Operation = 'OPERATION';
    case Agent = 'AGENT';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Operation => 'Operation',
            self::Agent => 'Agent',
        };
    }
}
