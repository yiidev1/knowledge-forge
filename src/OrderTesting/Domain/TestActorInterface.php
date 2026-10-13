<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

/**
 * Who is performing the current Order Testing action.
 *
 * ## Why this port exists at all
 *
 * Two realms may now start a test: an administrator holding an `admin_users` row, and an Order58
 * operation user holding nothing of the sort. The action that opens an attempt must record which, and it
 * cannot ask either realm directly — `OrderTestingBoundariesTest` forbids every file under
 * `src/OrderTesting/` from naming the agent realm's classes or routes, and from naming any other
 * business module — the audio, Order58, chat and rules modules alike.
 *
 * So the module states what it needs and nothing about where it comes from. The implementation lives
 * outside this module and is populated by the middleware that already proved who the caller is. That is
 * the same shape as the readers A2T declares for data it may not reach for itself.
 *
 * ## The id is realm-scoped, which is the whole reason the type travels with it
 *
 * `admin_users.id = 7` and `order58_agents.admin_id = 7` are different people. An implementation must
 * therefore never return a bare id: {@see initiatorType()} is what makes the number mean something, and
 * the two are written to `order_testing_attempts` as a pair.
 *
 * **Never** derived from the request body. The actor comes from the session the middleware verified, so
 * a posted `initiated_by_type` or `initiated_by_id` is ignored — a rule `OrderTestingDemoOrdersCest`
 * already pins with `thePostedActorIdIsIgnored`.
 */
interface TestActorInterface
{
    /** Which realm is acting, so the id below can be read against the right table. */
    public function initiatorType(): InitiatorType;

    /**
     * The acting user's id **within their own realm** — `admin_users.id` for an administrator, Order58's
     * own `admin_id` for an operation user (never `order58_agents.id`, which is a local mirror key that
     * changes if the mirror is ever rebuilt).
     */
    public function initiatorId(): int;

    /** The signed-in person's name, for attribution shown on screen. */
    public function displayName(): string;
}
