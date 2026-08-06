<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use DateTimeImmutable;

/**
 * Persistence for the synchronization state machine.
 *
 * {@see enqueue()} is coalescing: it returns null when an active run of the same operation already exists
 * (the database's unique `active_key` rejects the duplicate), so a second Sync click never doubles the
 * load on Order58. {@see claim()} is the atomic worker claim.
 */
interface SyncRunRepositoryInterface
{
    /**
     * Inserts a pending run, or returns null if an active run of the same (type, scope) already exists.
     *
     * @return int|null The new run id, or null when coalesced.
     */
    public function enqueue(
        Order58SyncType $type,
        ?int $scopeRef,
        ?int $requestedByAdminId,
        DateTimeImmutable $now,
    ): ?int;

    /**
     * Whether an active (pending|running) run of this operation exists — drives the disabled button state.
     */
    public function hasActive(Order58SyncType $type, ?int $scopeRef): bool;

    /**
     * @return list<SyncRun> Pending or resumable-running runs whose backoff has elapsed, oldest first.
     */
    public function findClaimable(int $limit, DateTimeImmutable $now): array;

    /**
     * Atomically transitions a run to `running` (from `pending`, or re-stamps a `running` one it is
     * resuming). Returns true if this worker holds the claim.
     */
    public function claim(int $id, DateTimeImmutable $now): bool;

    /**
     * Persists the page cursor + counters mid-run without changing status (resumable progress).
     */
    public function saveProgress(int $id, SyncProgress $progress, DateTimeImmutable $now): void;

    /**
     * Terminal transition: completed | completed_with_warnings | failed, with final counters and a
     * sanitized error/warning summary.
     */
    public function finish(
        int $id,
        SyncRunStatus $status,
        SyncProgress $progress,
        ?string $errorCode,
        ?string $errorMessage,
        DateTimeImmutable $now,
    ): void;

    /**
     * Returns a transiently-failed run to `pending` with a future retry time (preserving progress/cursor).
     */
    public function requeue(
        int $id,
        DateTimeImmutable $nextAttemptAt,
        ?string $errorCode,
        ?string $errorMessage,
        DateTimeImmutable $now,
    ): void;

    /**
     * Returns runs stuck in `running` since before $threshold to `pending`.
     *
     * @return int Number recovered.
     */
    public function recoverStuck(DateTimeImmutable $threshold, DateTimeImmutable $now): int;

    public function findById(int $id): ?SyncRun;

    /**
     * The latest run per top-level operation type (stores, knowledge, agents, rules), for the "latest status"
     * panels on Data Management and the Rules report.
     *
     * @return array<string, SyncRun> Keyed by {@see Order58SyncType::value}.
     */
    public function latestByType(): array;

    /**
     * The most recent completed `health` run, for the API-status panel (rendered from local state).
     */
    public function latestHealth(): ?SyncRun;

    /**
     * @return list<SyncRun> Recent runs, newest first, for the history table.
     */
    public function recent(int $limit): array;

    public function countActive(): int;
}
