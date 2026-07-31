<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Domain;

use DateTimeImmutable;

/**
 * Source-mapping operations for a knowledge base backed by an external store (Order58).
 *
 * Kept separate from {@see KnowledgeBaseRepositoryInterface} so the manual-CRUD side and its in-memory
 * test double are untouched. The unique `(source_system, source_store_id)` index guarantees one knowledge
 * base per store; a new store's knowledge base starts `pending`, and provisioning is deferred until the
 * store is active (enforced by the provisioning query, not here).
 */
interface KnowledgeBaseSourceRepositoryInterface
{
    public function findIdBySource(string $sourceSystem, int $sourceStoreId): ?int;

    /**
     * The Order58 source store id backing a knowledge base, or null when the knowledge base is not
     * Order58-sourced. Lets the Manage KB page offer a per-store "Sync Order58 knowledge" button.
     */
    public function findOrder58StoreId(int $knowledgeBaseId): ?int;

    /**
     * @return int The new knowledge base id.
     */
    public function createForSource(
        string $name,
        string $slug,
        string $sourceSystem,
        int $sourceStoreId,
        ?string $sourceName,
        bool $sourceActive,
        DateTimeImmutable $now,
    ): int;

    /**
     * Refreshes the mapped knowledge base from the current store data (name, source status, last-synced).
     * Does not touch `agent_enabled` — that is the administrator's local switch.
     */
    public function updateSourceState(
        int $id,
        string $name,
        ?string $sourceName,
        bool $sourceActive,
        DateTimeImmutable $syncedAt,
    ): void;

    /**
     * Marks a knowledge base's source store inactive (its store disappeared from the scan). The knowledge
     * base, vector store and history are preserved; it is only hidden from agent selection.
     */
    public function markSourceInactive(int $id, DateTimeImmutable $now): void;

    /**
     * The administrator's local switch for whether a source-backed knowledge base is offered to agents.
     * Independent of `source_active`; a normal Order58 sync never changes it.
     */
    public function setAgentEnabled(int $id, bool $enabled, DateTimeImmutable $now): void;

    /**
     * Reconciliation: sets `source_active` on a mapped knowledge base only when it differs, preserving
     * `agent_enabled`, the vector store, documents and conversations. Returns whether a row actually changed
     * (so the caller can report a corrected count). Independent of `_sync_hash`.
     */
    public function reconcileSourceActive(int $id, bool $active, DateTimeImmutable $now): bool;

    /**
     * Admin summary counts for source-backed knowledge bases: total mapped, agent-enabled (the local
     * override) and vector-store-ready — each an independent axis from the mirror's active count.
     */
    public function countByStatus(string $sourceSystem): SourceKnowledgeBaseCounts;
}
