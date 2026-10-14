<?php

declare(strict_types=1);

namespace App\OrderTesting\Infrastructure;

use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\TestActorInterface;
use App\Shared\Web\Actor\CurrentActor;
use LogicException;

/**
 * Adapts the request's resolved actor to the shape this module asked for.
 *
 * Nine lines of mapping and nothing else on purpose. {@see CurrentActor} belongs to `App\Shared`, which
 * this module is allowed to name; the realm that populated it — administrator session or Order58
 * operation session — is not, and does not need to be. This file is the whole of what Order Testing
 * knows about who is signed in.
 *
 * Three realms reach it now — administrator, operation user and, on the practice surface only, an
 * Order58 agent. Which of them is acting is read from the holder's realm rather than inferred from
 * which id happens to be set, so a realm added without a branch here fails loudly instead of being
 * attributed to an administrator.
 *
 * The vocabularies differ by table and both are pre-existing: `order_testing_attempts` spells its
 * realms in UPPER case, the audio tables in lower. The translation happens here rather than anywhere a
 * row is written, so neither spelling leaks into the other's module.
 */
final readonly class CurrentActorTestActor implements TestActorInterface
{
    public function __construct(private CurrentActor $actor) {}

    public function initiatorType(): InitiatorType
    {
        // A match on the realm, not a chain of ternaries: a third realm added to the holder without a
        // branch here would otherwise be silently recorded as an administrator.
        return match ($this->actor->realm()) {
            CurrentActor::REALM_OPERATION => InitiatorType::Operation,
            CurrentActor::REALM_AGENT => InitiatorType::Agent,
            default => InitiatorType::Admin,
        };
    }

    public function initiatorId(): int
    {
        // Whichever id the realm populated. Never two, never another realm's — see CurrentActor.
        $id = match ($this->actor->realm()) {
            CurrentActor::REALM_OPERATION => $this->actor->operationId(),
            CurrentActor::REALM_AGENT => $this->actor->agentId(),
            default => $this->actor->adminId(),
        };

        if ($id === null) {
            // Unreachable behind the middleware that sets the actor, and a bug worth failing on rather
            // than attributing an attempt to id 0 — a row nobody could trace afterwards.
            throw new LogicException('The resolved actor carries no id for its own realm.');
        }

        return $id;
    }

    public function displayName(): string
    {
        return $this->actor->displayName();
    }
}
