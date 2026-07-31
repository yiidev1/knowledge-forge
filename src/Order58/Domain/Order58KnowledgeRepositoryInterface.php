<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use DateTimeImmutable;

/**
 * Persistence for the mirrored Order58 knowledge records.
 */
interface Order58KnowledgeRepositoryInterface
{
    public function findSyncHash(int $sourceId): ?string;

    public function save(KnowledgeMirror $record, int $runId, DateTimeImmutable $now): void;

    public function markSeen(int $sourceId, int $runId, DateTimeImmutable $now): void;

    /**
     * Deactivates active records not seen by this full-knowledge run (NULL-safe).
     *
     * @return list<array{source_id: int, store_source_id: int}> Deactivated records, so their generated
     *                                                            documents can be scheduled for removal.
     */
    public function deactivateNotSeen(int $runId, DateTimeImmutable $now): array;

    /**
     * Same as {@see deactivateNotSeen()} but scoped to one store — never touches other stores' records.
     *
     * @return list<array{source_id: int, store_source_id: int}>
     */
    public function deactivateNotSeenForStore(int $storeSourceId, int $runId, DateTimeImmutable $now): array;

    /**
     * @return list<KnowledgeMirror> Active records for one store, for the knowledge-base detail page.
     */
    public function findAllForStore(int $storeSourceId): array;

    public function countAll(): int;

    public function countActive(): int;
}
