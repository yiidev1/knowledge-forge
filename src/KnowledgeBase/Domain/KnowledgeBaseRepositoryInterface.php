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
     *
     * @return list<KnowledgeBase> Ordered by name.
     */
    public function findAll(bool $includeArchived = false): array;

    public function countActive(): int;

    /**
     * @return int The id of the newly created knowledge base.
     */
    public function create(
        string $name,
        string $slug,
        ?string $description,
        ?string $systemInstructions,
    ): int;

    public function updateDetails(
        int $id,
        string $name,
        ?string $description,
        ?string $systemInstructions,
    ): void;

    public function updateStatus(int $id, KnowledgeBaseStatus $status): void;
}
