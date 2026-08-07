<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\KnowledgeBase;

use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\KnowledgeBase\Domain\Order58SourceState;
use App\KnowledgeBase\Domain\SourceKnowledgeBaseCounts;
use BadMethodCallException;
use DateTimeImmutable;

/**
 * In-memory source mapping for tests. Only {@see findOrder58StoreId} is exercised by
 * {@see \App\Chat\Application\ChatAvailabilityPolicy} (to tell an Order58-linked base from a plain one);
 * the write/report methods throw if a test unexpectedly reaches them.
 */
final class InMemoryKnowledgeBaseSourceRepository implements KnowledgeBaseSourceRepositoryInterface
{
    /** @var array<int, int> knowledge base id => Order58 store id (present ⇒ Order58-linked). */
    private array $order58StoreIds = [];

    /** @var array<int, bool> knowledge base id => whether its Order58 source store is active. */
    private array $order58Active = [];

    public function linkToOrder58(int $knowledgeBaseId, int $storeId, bool $active = true): void
    {
        $this->order58StoreIds[$knowledgeBaseId] = $storeId;
        $this->order58Active[$knowledgeBaseId] = $active;
    }

    public function findOrder58StoreId(int $knowledgeBaseId): ?int
    {
        return $this->order58StoreIds[$knowledgeBaseId] ?? null;
    }

    public function findOrder58SourceState(int $knowledgeBaseId): ?Order58SourceState
    {
        $storeId = $this->order58StoreIds[$knowledgeBaseId] ?? null;
        if ($storeId === null) {
            return null;
        }

        return new Order58SourceState($storeId, $this->order58Active[$knowledgeBaseId] ?? true);
    }

    public function findIdBySource(string $sourceSystem, int $sourceStoreId): ?int
    {
        throw self::unused();
    }

    public function createForSource(
        string $name,
        string $slug,
        string $sourceSystem,
        int $sourceStoreId,
        ?string $sourceName,
        bool $sourceActive,
        DateTimeImmutable $now,
    ): int {
        throw self::unused();
    }

    public function updateSourceState(
        int $id,
        string $name,
        ?string $sourceName,
        bool $sourceActive,
        DateTimeImmutable $syncedAt,
    ): void {
        throw self::unused();
    }

    public function markSourceInactive(int $id, DateTimeImmutable $now): void
    {
        throw self::unused();
    }

    public function setAgentEnabled(int $id, bool $enabled, DateTimeImmutable $now): void
    {
        throw self::unused();
    }

    public function reconcileSourceActive(int $id, bool $active, DateTimeImmutable $now): bool
    {
        throw self::unused();
    }

    public function countByStatus(string $sourceSystem): SourceKnowledgeBaseCounts
    {
        throw self::unused();
    }

    private static function unused(): BadMethodCallException
    {
        return new BadMethodCallException('Not used by ChatAvailabilityPolicy tests.');
    }
}
