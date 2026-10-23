<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

use DateTimeImmutable;

/**
 * Stores and reads who set out to test what.
 *
 * The one-open-attempt rule lives in the SQL here rather than in a service that reads and then writes:
 * two administrators clicking the same row in the same second must not both be told they hold the slot,
 * and only the database can settle that.
 */
interface TestAttemptRepositoryInterface
{
    /**
     * The attempt still holding this store and source order, or null.
     *
     * "Open" means {@see AttemptStatus::isOpen()} and not yet past `expires_at`. An attempt whose window
     * has passed is not returned and does not block — it is swept to `EXPIRED` separately, so a forgotten
     * click cannot lock a row out forever.
     */
    public function openFor(int $storeSourceId, int $sourceOrderId, DateTimeImmutable $now): ?TestAttempt;

    /**
     * Start one, or return the one that already holds the slot.
     *
     * Atomic: the insert is conditional on no open attempt existing, so of two simultaneous clicks
     * exactly one creates a row and the other is handed the winner's. The caller then decides what that
     * means — the same administrator carries on, a different one is refused and told who holds it.
     */
    public function start(
        int $storeSourceId,
        int $sourceOrderId,
        InitiatorType $type,
        int $initiatedById,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): AttemptStart;

    /**
     * Credit a demo order to an attempt.
     *
     * Moves `STARTED` to `MATCHED` and records the first demo order id and the moment. Called again for
     * the second and third order of the same session, where it leaves `matched_demo_order_id` alone —
     * that column is the first result, not the latest.
     */
    /**
     * The attempt that was running when a demo order was **created**, if exactly one was.
     *
     * This is the only honest basis for attribution available. The demo JSON carries no agent id, no
     * attempt id and no correlation token, so the question "who made this order?" can only be answered
     * by "whose attempt was open at the moment Order58 says it was created?".
     *
     * `$createdAt` must be the provider's own creation instant, never the import instant. Imports run
     * manually and have been observed a full day after the order was created, so an attempt open at
     * import time is almost certainly somebody else's — crediting it would invent a training record.
     *
     * Status is deliberately not filtered: by the time the file is imported the attempt that produced
     * it has normally expired, and an expired attempt is still a true record of who was practising.
     *
     * Returns null when nothing covers the instant **and** when more than one thing does. One open
     * attempt per source order is enforced at `start()`, so overlapping windows should be impossible;
     * if the data ever says otherwise, the honest answer is Unmatched rather than a guess.
     */
    public function attemptCoveringInstant(
        int $storeSourceId,
        int $sourceOrderId,
        DateTimeImmutable $createdAt,
    ): ?TestAttempt;

    public function recordMatch(int $attemptId, int $demoOrderId, DateTimeImmutable $now): void;

    /**
     * Close every attempt whose window has passed.
     *
     * Returns the number closed. Swept by the importer rather than by anything of its own: an attempt
     * only matters when a demo order arrives or when somebody tries to start a new one, and both of
     * those already run this. Nothing in this feature runs on a clock.
     */
    public function expireOverdue(DateTimeImmutable $now): int;

    /**
     * Attempts by local row id, for display.
     *
     * @param list<int> $ids
     *
     * @return array<int, TestAttempt> keyed by id; ids with no row are absent
     */
    public function byIds(array $ids): array;

    /**
     * Every attempt ever made on one source order, oldest first.
     *
     * Oldest first because Try 1 is the first one somebody made, and that ordering must not change
     * when a file is imported late — numbering follows the attempts, not the demo orders.
     *
     * `$onlyActorType`/`$onlyActorId` narrow to a single practitioner. That filter exists **here**
     * rather than in the page because it is an authorization boundary: an agent may see their own
     * history and nobody else's, and a template that filtered a full list would still have fetched
     * the rest.
     *
     * @return list<TestAttempt>
     */
    public function historyFor(
        int $storeSourceId,
        int $sourceOrderId,
        ?InitiatorType $onlyActorType = null,
        ?int $onlyActorId = null,
    ): array;
}
