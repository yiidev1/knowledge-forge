<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use DateTimeImmutable;

/**
 * Persistence for the mirrored Order58 rule records. Follows the existing Order58 mirror convention (the port
 * lives in Domain alongside {@see Order58KnowledgeRepositoryInterface}).
 */
interface Order58RuleRepositoryInterface
{
    public function findSyncHash(int $sourceId): ?string;

    /**
     * The mirror row's primary key for a source rule id (used to audit-link it to a canonical rule).
     */
    public function findIdBySourceId(int $sourceId): ?int;

    public function save(RuleMirror $record, int $runId, DateTimeImmutable $now): void;

    /**
     * Stamps last-seen for this run and reconciles authoritative `is_active` without rewriting content.
     * Order58 Rules currently omit an explicit active flag, so presence in a scan means active (`$active =
     * true`). When an explicit flag appears upstream, pass it through so unchanged content still refreshes
     * lifecycle state.
     *
     * @return bool true when `is_active` changed (e.g. stale-inactive → active)
     */
    public function markSeen(int $sourceId, int $runId, DateTimeImmutable $now, bool $active = true): bool;

    /**
     * Soft-deactivates active records not seen by this full rules run (NULL-safe). Never deletes a row.
     *
     * @return list<array{source_id: int, record_id: int}> Deactivated records, so their canonical rules'
     *                                                      active flags can be recomputed.
     */
    public function deactivateNotSeen(int $runId, DateTimeImmutable $now): array;

    public function countAll(): int;

    public function countActive(): int;
}
