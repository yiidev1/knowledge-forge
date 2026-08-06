<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Rules;

use App\Rules\Contract\RuleCatalogRepositoryInterface;
use DateTimeImmutable;

use function count;

/**
 * In-memory {@see RuleCatalogRepositoryInterface} for unit tests. Models canonical rules and their source
 * links; `recomputeActive()` marks a canonical inactive once it has no remaining source links (a faithful
 * proxy for the real "no active source" rule for the relink case — the cross-table variant is covered by the
 * integration test).
 */
final class InMemoryRuleCatalogRepository implements RuleCatalogRepositoryInterface
{
    /** @var array<int, array{canonical_hash: string, description_hash: string, title: string, content: string, is_active: int, is_globally_available: int, scope_type: string, classification_status: string}> */
    private array $canonicals = [];

    /** @var array<int, array{canonical_id: int, relation_type: string}> */
    private array $links = [];

    private int $nextId = 0;

    /** @var list<int> Canonical ids passed to recomputeActive(), in order. */
    public array $recomputeCalls = [];

    public function findIdByCanonicalHash(string $canonicalHash): ?int
    {
        foreach ($this->canonicals as $id => $row) {
            if ($row['canonical_hash'] === $canonicalHash) {
                return $id;
            }
        }

        return null;
    }

    public function insertCanonical(
        string $canonicalHash,
        string $descriptionHash,
        string $title,
        string $content,
        DateTimeImmutable $now,
    ): int {
        $id = ++$this->nextId;
        $this->canonicals[$id] = [
            'canonical_hash' => $canonicalHash,
            'description_hash' => $descriptionHash,
            'title' => $title,
            'content' => $content,
            'is_active' => 1,
            'is_globally_available' => 1,
            'scope_type' => 'unresolved',
            'classification_status' => 'pending',
        ];

        return $id;
    }

    public function findSourceLink(int $order58RuleRecordId): ?array
    {
        return $this->links[$order58RuleRecordId] ?? null;
    }

    public function countSourcesForCanonical(int $canonicalId): int
    {
        $count = 0;
        foreach ($this->links as $link) {
            if ($link['canonical_id'] === $canonicalId) {
                $count++;
            }
        }

        return $count;
    }

    public function insertSourceLink(int $canonicalId, int $order58RuleRecordId, string $relationType, DateTimeImmutable $now): void
    {
        $this->links[$order58RuleRecordId] = ['canonical_id' => $canonicalId, 'relation_type' => $relationType];
    }

    public function relinkSource(int $order58RuleRecordId, int $newCanonicalId, string $relationType, DateTimeImmutable $now): void
    {
        $this->links[$order58RuleRecordId] = ['canonical_id' => $newCanonicalId, 'relation_type' => $relationType];
    }

    public function recomputeActive(int $canonicalId, DateTimeImmutable $now): void
    {
        $this->recomputeCalls[] = $canonicalId;
        if (isset($this->canonicals[$canonicalId])) {
            $this->canonicals[$canonicalId]['is_active'] = $this->countSourcesForCanonical($canonicalId) > 0 ? 1 : 0;
        }
    }

    public function findCanonicalIdForRecord(int $order58RuleRecordId): ?int
    {
        return $this->links[$order58RuleRecordId]['canonical_id'] ?? null;
    }

    public function findClassification(int $canonicalId): ?array
    {
        $row = $this->canonicals[$canonicalId] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'title' => $row['title'],
            'content' => $row['content'],
            'canonical_hash' => $row['canonical_hash'],
            'scope_type' => $row['scope_type'],
            'classification_status' => $row['classification_status'],
            'is_active' => (bool) $row['is_active'],
            'is_globally_available' => (bool) $row['is_globally_available'],
        ];
    }

    public function updateClassification(
        int $canonicalId,
        string $scopeType,
        string $classificationStatus,
        ?string $reason,
        ?string $detectedStoreText,
        ?int $reviewedByAdminId,
        DateTimeImmutable $now,
    ): void {
        if (isset($this->canonicals[$canonicalId])) {
            $this->canonicals[$canonicalId]['scope_type'] = $scopeType;
            $this->canonicals[$canonicalId]['classification_status'] = $classificationStatus;
        }
    }

    public function setGloballyAvailable(int $canonicalId, bool $available, DateTimeImmutable $now): void
    {
        if (isset($this->canonicals[$canonicalId])) {
            $this->canonicals[$canonicalId]['is_globally_available'] = $available ? 1 : 0;
        }
    }

    public function findSourceStoreIdForCanonical(int $canonicalId): ?int
    {
        return null;
    }

    public function listActiveCanonicalIds(): array
    {
        $ids = [];
        foreach ($this->canonicals as $id => $row) {
            if ($row['is_active'] === 1) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    // --- Test helpers ---

    public function canonicalCount(): int
    {
        return count($this->canonicals);
    }

    public function linkCount(): int
    {
        return count($this->links);
    }

    public function isActive(int $canonicalId): bool
    {
        return (bool) ($this->canonicals[$canonicalId]['is_active'] ?? 0);
    }

    public function canonicalIdFor(int $order58RuleRecordId): ?int
    {
        return $this->links[$order58RuleRecordId]['canonical_id'] ?? null;
    }

    public function relationFor(int $order58RuleRecordId): ?string
    {
        return $this->links[$order58RuleRecordId]['relation_type'] ?? null;
    }
}
