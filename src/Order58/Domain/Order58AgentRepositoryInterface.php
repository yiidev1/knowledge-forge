<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use DateTimeImmutable;

/**
 * Persistence for the mirrored Order58 agents (safe fields only).
 */
interface Order58AgentRepositoryInterface
{
    public function findSyncHash(int $adminId): ?string;

    public function save(AgentMirror $agent, int $runId, DateTimeImmutable $now): void;

    public function markSeen(int $adminId, int $runId, DateTimeImmutable $now): void;

    /**
     * Marks agents not seen by this run as inactive (`status = 'inactive'`), NULL-safe on the run marker.
     *
     * @return int Number deactivated.
     */
    public function deactivateNotSeen(int $runId, DateTimeImmutable $now): int;

    /**
     * @return list<AgentMirror> Newest activity first, for the admin Agents page.
     */
    public function findAllForDisplay(int $limit = 500): array;

    public function countAll(): int;

    public function countActive(): int;
}
