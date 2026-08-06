<?php

declare(strict_types=1);

namespace App\Rules\Contract;

use DateTimeImmutable;

/**
 * Persistence for the canonical rule catalog and its source audit links.
 *
 * Per the Rules module convention (and the reviewed plan), application ports live under Contract/. Every method
 * is a small, composable primitive; {@see \App\Rules\Application\RuleCatalogService} orchestrates them inside a
 * single transaction so linking a source rule is atomic and idempotent.
 */
interface RuleCatalogRepositoryInterface
{
    public function findIdByCanonicalHash(string $canonicalHash): ?int;

    /**
     * Inserts a new canonical rule (pending/unresolved classification) and returns its id.
     */
    public function insertCanonical(
        string $canonicalHash,
        string $descriptionHash,
        string $title,
        string $content,
        DateTimeImmutable $now,
    ): int;

    /**
     * The current source→canonical link for a raw rule record, or null if it is not linked yet.
     *
     * @return array{canonical_id: int, relation_type: string}|null
     */
    public function findSourceLink(int $order58RuleRecordId): ?array;

    public function countSourcesForCanonical(int $canonicalId): int;

    public function insertSourceLink(
        int $canonicalId,
        int $order58RuleRecordId,
        string $relationType,
        DateTimeImmutable $now,
    ): void;

    /**
     * Moves an existing source link to a different canonical rule (upstream content changed).
     */
    public function relinkSource(
        int $order58RuleRecordId,
        int $newCanonicalId,
        string $relationType,
        DateTimeImmutable $now,
    ): void;

    /**
     * Recomputes a canonical rule's `is_active` flag: active when at least one linked source record is active.
     */
    public function recomputeActive(int $canonicalId, DateTimeImmutable $now): void;

    /**
     * The canonical rule a raw source record is linked to, if any (used to recompute active flags after a sweep).
     */
    public function findCanonicalIdForRecord(int $order58RuleRecordId): ?int;

    /**
     * A canonical rule's classification-relevant fields, or null if it does not exist.
     *
     * @return array{title: string, content: string, canonical_hash: string, scope_type: string, classification_status: string, is_active: bool, is_globally_available: bool}|null
     */
    public function findClassification(int $canonicalId): ?array;

    /**
     * Updates only the classification columns of a canonical rule (never its content/hash).
     */
    public function updateClassification(
        int $canonicalId,
        string $scopeType,
        string $classificationStatus,
        ?string $reason,
        ?string $detectedStoreText,
        ?int $reviewedByAdminId,
        DateTimeImmutable $now,
    ): void;

    /**
     * Sets a canonical rule's explicit retrieval-availability flag (separate from classification). Idempotent.
     */
    public function setGloballyAvailable(int $canonicalId, bool $available, DateTimeImmutable $now): void;

    /**
     * The authoritative source store id for a canonical rule, taken from any active linked source record.
     * Null today (the Rules API carries no store id yet) — wired for future compatibility.
     */
    public function findSourceStoreIdForCanonical(int $canonicalId): ?int;

    /**
     * @return list<int> Ids of active canonical rules (for a full re-classification pass).
     */
    public function listActiveCanonicalIds(): array;
}
