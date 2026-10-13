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

    public function actorType(): AudioActorType
    {
        return $this->actor->isOperation() ? AudioActorType::Operation : AudioActorType::Admin;
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
