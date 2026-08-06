<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\KnowledgeBase;

use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBaseStatus;
use App\KnowledgeBase\Domain\VectorStoreStatus;
use DateTimeImmutable;
use DateTimeZone;

use function array_values;
use function usort;

/**
 * In-memory knowledge-base repository for unit tests, with no database.
 */
final class InMemoryKnowledgeBaseRepository implements KnowledgeBaseRepositoryInterface
{
    /** @var array<int, KnowledgeBase> */
    private array $items = [];

    private int $nextId = 1;

    public function findById(int $id): ?KnowledgeBase
    {
        return $this->items[$id] ?? null;
    }

    public function findBySlug(string $slug): ?KnowledgeBase
    {
        foreach ($this->items as $kb) {
            if ($kb->slug() === $slug) {
                return $kb;
            }
        }

        return null;
    }

    public function slugExists(string $slug): bool
    {
        return $this->findBySlug($slug) !== null;
    }

    public function findAll(bool $includeArchived = false, bool $excludeSystem = false): array
    {
        // The fake holds only normal ('store') knowledge bases, so $excludeSystem never changes the result.
        $result = [];
        foreach ($this->items as $kb) {
            if ($includeArchived || !$kb->isArchived()) {
                $result[] = $kb;
            }
        }

        usort($result, static fn(KnowledgeBase $a, KnowledgeBase $b): int => $a->name() <=> $b->name());

        return array_values($result);
    }

    public function countActive(bool $excludeSystem = false): int
    {
        return count($this->findAll(false, $excludeSystem));
    }

    public function create(string $name, string $slug, ?string $description, ?string $systemInstructions): int
    {
        $id = $this->nextId++;
        $this->items[$id] = $this->make($id, $name, $slug, $description, $systemInstructions, KnowledgeBaseStatus::Active);

        return $id;
    }

    public function createSystem(string $name, string $slug, string $purpose, string $sourceSystem): int
    {
        // The fake does not model the purpose/source discriminators; it stores the base like any other.
        return $this->create($name, $slug, null, null);
    }

    public function updateDetails(int $id, string $name, ?string $description, ?string $systemInstructions): void
    {
        $existing = $this->items[$id] ?? null;
        if ($existing === null) {
            return;
        }

        $this->items[$id] = $this->make($id, $name, $existing->slug(), $description, $systemInstructions, $existing->status());
    }

    public function updateStatus(int $id, KnowledgeBaseStatus $status): void
    {
        $existing = $this->items[$id] ?? null;
        if ($existing === null) {
            return;
        }

        $this->items[$id] = $this->make(
            $id,
            $existing->name(),
            $existing->slug(),
            $existing->description(),
            $existing->systemInstructions(),
            $status,
        );
    }

    /**
     * Seeds a knowledge base with a provisioned vector store id, for tests that need one without
     * driving the provisioning flow.
     *
     * The two optional arguments matter for reconciliation tests. `updateStatus()` here rebuilds the
     * record through `make()`, which deliberately has no vector store id — so archiving a seeded base
     * would silently strip the very id the test is about. An archived base that still owns billed
     * storage is a state that must be constructible directly.
     */
    public function seedReady(
        int $id,
        string $slug,
        string $vectorStoreId,
        KnowledgeBaseStatus $status = KnowledgeBaseStatus::Active,
        VectorStoreStatus $vectorStoreStatus = VectorStoreStatus::Ready,
    ): void {
        $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $this->items[$id] = new KnowledgeBase(
            $id,
            'KB ' . $id,
            $slug,
            null,
            null,
            $vectorStoreId,
            $vectorStoreStatus,
            null,
            $status,
            $now,
            $now,
        );
        if ($id >= $this->nextId) {
            $this->nextId = $id + 1;
        }
    }

    private function make(
        int $id,
        string $name,
        string $slug,
        ?string $description,
        ?string $systemInstructions,
        KnowledgeBaseStatus $status,
    ): KnowledgeBase {
        $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        return new KnowledgeBase(
            $id,
            $name,
            $slug,
            $description,
            $systemInstructions,
            null,
            VectorStoreStatus::Pending,
            null,
            $status,
            $now,
            $now,
        );
    }
}
