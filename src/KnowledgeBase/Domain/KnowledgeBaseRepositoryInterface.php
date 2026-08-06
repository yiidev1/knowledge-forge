<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Domain;

/**
 * Persistence boundary for knowledge bases.
 *
 * Returns typed {@see KnowledgeBase} entities. Write methods take primitive arguments and return ids
 * rather than accepting a mutable entity, keeping the entity a read model.
 */
interface KnowledgeBaseRepositoryInterface
{
    public function findById(int $id): ?KnowledgeBase;

    public function findBySlug(string $slug): ?KnowledgeBase;

    public function slugExists(string $slug): bool;

    /**
     * @param bool $includeArchived When false, archived knowledge bases are omitted.
     * @param bool $excludeSystem    When true, system knowledge bases (purpose <> 'store', e.g. the hidden
     *                               Common-Rules base) are omitted. Defaults to false so internal callers
     *                               (usage reconciliation, provisioning, cleanup) still see every base.
     *
     * @return list<KnowledgeBase> Ordered by name.
     */
    public function findAll(bool $includeArchived = false, bool $excludeSystem = false): array;

    /**
     * @param bool $excludeSystem When true, system knowledge bases are not counted (see {@see findAll()}).
     */
    public function countActive(bool $excludeSystem = false): int;

    /**
     * @return int The id of the newly created knowledge base.
     */
    public function create(
        string $name,
        string $slug,
        ?string $description,
        ?string $systemInstructions,
    ): int;

    /**
     * Idempotently used to create a hidden, system-managed knowledge base with a specific `purpose` and
     * `source_system`, `agent_enabled = 0` and no store source. Returns the new id.
     */
    public function createSystem(
        string $name,
        string $slug,
        string $purpose,
        string $sourceSystem,
    ): int;

    public function updateDetails(
        int $id,
        string $name,
        ?string $description,
        ?string $systemInstructions,
    ): void;

    public function updateStatus(int $id, KnowledgeBaseStatus $status): void;
}
