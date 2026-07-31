<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use DateTimeImmutable;

/**
 * Persistence for the mirrored Order58 stores.
 *
 * The mark-and-sweep marker is central: {@see save()} and {@see markSeen()} both stamp the current run id,
 * and {@see deactivateNotSeen()} deactivates only rows this run did not touch — and only the drainer calls
 * it, after the final page has succeeded.
 */
interface Order58StoreRepositoryInterface
{
    /**
     * The last stored `_sync_hash` for a source store, or null if it has never been mirrored. Used for
     * change detection: an unchanged hash means skip the write and the document regeneration.
     */
    public function findSyncHash(int $sourceId): ?string;

    /**
     * The full mirror row for a store (with its curated snapshot), for the "rebuild generated documents"
     * action which regenerates from local data without calling the API.
     */
    public function findBySourceId(int $sourceId): ?StoreMirror;

    /**
     * Every mirror row (with its decoded snapshot), for the one-off active-status reconciliation. Bounded by
     * the store count; not a hot path.
     *
     * @return list<StoreMirror>
     */
    public function allMirrors(): array;

    /**
     * Sets a store's active column directly (used only by reconciliation, from the authoritative snapshot).
     * Does not touch the sync hash, the snapshot, or the seen marker, so a subsequent sync is unaffected.
     */
    public function setActive(int $sourceId, bool $active, DateTimeImmutable $now): void;

    /**
     * Inserts or updates the full mirror row and stamps it as seen by this run.
     */
    public function save(StoreMirror $store, int $runId, DateTimeImmutable $now): void;

    /**
     * Stamps an unchanged row as seen by this run (updates `last_seen_sync_run_id` + `synced_at` only), so
     * the sweep does not deactivate a record the source still returns.
     */
    public function markSeen(int $sourceId, int $runId, DateTimeImmutable $now): void;

    /**
     * Deactivates active rows not seen by this run (NULL-safe: also catches rows whose marker is NULL).
     *
     * @return list<int> The source ids that were deactivated, so their knowledge bases can be updated.
     */
    public function deactivateNotSeen(int $runId, DateTimeImmutable $now): array;

    public function countAll(): int;

    public function countActive(): int;
}
