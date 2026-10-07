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
}
