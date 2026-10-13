<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\AudioActorInterface;
use App\AudioToText\Domain\AudioActorType;
use App\Shared\Web\Actor\CurrentActor;

/**
 * Adapts the request's resolved actor to the shape this module asked for.
 *
 * The mirror image of Order Testing's adapter, and deliberately just as thin. {@see CurrentActor} lives
 * in `App\Shared`, which A2T may name and already names throughout; the module that authenticated the
 * person is not named here and does not need to be.
 *
 * `adminId()` and `operationId()` are mutually exclusive by construction — the holder clears one when it
 * sets the other — which is what the pair CHECK on each audio table then enforces in the database.
 */
final readonly class CurrentActorAudioActor implements AudioActorInterface
{
    public function __construct(private CurrentActor $actor) {}

    /**
     * The realm, asked for in a way that cannot mistake "nobody" for "an administrator".
     *
     * `isOperation()` alone answered false for an unresolved holder, so a route wired without the
     * middleware that fills it looked exactly like an administrator with no id — and the write failed
     * several frames later saying it needed a positive `admin_users` id, which is a true sentence about
     * the wrong problem. That is precisely how the upload and replacement actions on the store page came
     * to be broken without anybody reading the error as "this route has no actor".
     *
     * `realm()` throws its own message for that case, naming the actual fault.
     */
    public function actorType(): AudioActorType
    {
        return $this->actor->realm() === CurrentActor::REALM_OPERATION
            ? AudioActorType::Operation
            : AudioActorType::Admin;
    }

    public function adminId(): ?int
    {
        return $this->actor->isOperation() ? null : $this->actor->adminId();
    }

    public function operationId(): ?int
    {
        return $this->actor->isOperation() ? $this->actor->operationId() : null;
    }

    public function displayName(): string
    {
        return $this->actor->displayName();
    }
}
