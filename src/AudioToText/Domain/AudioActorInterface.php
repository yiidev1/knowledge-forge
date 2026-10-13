<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * Who is performing the current audio action.
 *
 * ## Why A2T declares its own port instead of reusing Order Testing's
 *
 * `ModuleIsolationTest` forbids every file under `src/AudioToText/` from naming another business module,
 * and forbids every file outside it from naming `App\AudioToText`. Order Testing has the mirror-image
 * restriction. Neither module may therefore hold the other's actor abstraction, so each declares the one
 * it needs and a single holder outside both implements the pair. One signed-in person, two ports, no
 * dependency between the modules — which is what keeps both isolation tests green without edits.
 *
 * ## An id alone is not an identity
 *
 * `adminId()` and `operationId()` are **mutually exclusive**: exactly one is non-null, and
 * {@see actorType()} says which. The audio tables store them in separate columns under a pair CHECK for
 * the same reason — an `admin_users.id` and an Order58 `admin_id` are different people even when the
 * number matches, and a single column could not tell them apart after the fact.
 *
 * Always resolved from the verified session, never from request input.
 */
interface AudioActorInterface
{
    /** Which realm is acting, and therefore which of the two ids below is populated. */
    public function actorType(): AudioActorType;

    /** `admin_users.id`, or null when an operation user is acting. */
    public function adminId(): ?int;

    /** Order58's own `admin_id`, or null when an administrator is acting. */
    public function operationId(): ?int;

    /** The signed-in person's name, for attribution shown on screen. */
    public function displayName(): string;
}
