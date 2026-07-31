<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Infrastructure;

use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBaseStatus;
use App\KnowledgeBase\Domain\SourceKnowledgeBaseCounts;
use App\KnowledgeBase\Domain\VectorStoreStatus;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;

use function is_array;
use function is_numeric;

/**
 * MySQL-backed source-mapping operations over the `knowledge_bases` table.
 */
final readonly class DbKnowledgeBaseSourceRepository implements KnowledgeBaseSourceRepositoryInterface
{
    private const TABLE = '{{%knowledge_bases}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findIdBySource(string $sourceSystem, int $sourceStoreId): ?int
    {
        $value = $this->connection
            ->createQuery()
            ->select('id')
            ->from(self::TABLE)
            ->where(['source_system' => $sourceSystem, 'source_store_id' => $sourceStoreId])
            ->limit(1)
            ->scalar();

        return is_numeric($value) ? (int) $value : null;
    }

    public function findOrder58StoreId(int $knowledgeBaseId): ?int
    {
        $value = $this->connection
            ->createQuery()
            ->select('source_store_id')
            ->from(self::TABLE)
            ->where(['id' => $knowledgeBaseId, 'source_system' => 'order58'])
            ->limit(1)
            ->scalar();

        return is_numeric($value) ? (int) $value : null;
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
        $ts = DbDateTime::format($now);

        $this->connection->createCommand()->insert(self::TABLE, [
            'name' => $name,
            'slug' => $slug,
            'source_system' => $sourceSystem,
            'source_store_id' => $sourceStoreId,
            'source_name' => $sourceName,
            'source_active' => $sourceActive ? 1 : 0,
            // Enabled for agent use by default; an inactive store is deferred via source_active, not here.
            'agent_enabled' => 1,
            'vector_store_status' => VectorStoreStatus::Pending->value,
            'status' => KnowledgeBaseStatus::Active->value,
            'last_source_synced_at' => $ts,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function updateSourceState(
        int $id,
        string $name,
        ?string $sourceName,
        bool $sourceActive,
        DateTimeImmutable $syncedAt,
    ): void {
        $ts = DbDateTime::format($syncedAt);

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'name' => $name,
                'source_name' => $sourceName,
                'source_active' => $sourceActive ? 1 : 0,
                'last_source_synced_at' => $ts,
                'updated_at' => $ts,
            ],
            ['id' => $id],
        )->execute();
    }

    public function markSourceInactive(int $id, DateTimeImmutable $now): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            ['source_active' => 0, 'updated_at' => DbDateTime::format($now)],
            ['id' => $id],
        )->execute();
    }

    public function setAgentEnabled(int $id, bool $enabled, DateTimeImmutable $now): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            ['agent_enabled' => $enabled ? 1 : 0, 'updated_at' => DbDateTime::format($now)],
            ['id' => $id],
        )->execute();
    }

    public function reconcileSourceActive(int $id, bool $active, DateTimeImmutable $now): bool
    {
        // Only source_active is written — agent_enabled and every other column are untouched. The `<>`
        // guard means an already-correct row is not rewritten and is not counted as corrected.
        $affected = $this->connection->createCommand()->update(
            self::TABLE,
            ['source_active' => $active ? 1 : 0, 'updated_at' => DbDateTime::format($now)],
            ['and', ['id' => $id], ['<>', 'source_active', $active ? 1 : 0]],
        )->execute();

        return $affected > 0;
    }

    public function countByStatus(string $sourceSystem): SourceKnowledgeBaseCounts
    {
        $row = $this->connection
            ->createQuery()
            ->select([
                'total' => new Expression('COUNT(*)'),
                'agent_enabled' => new Expression('SUM(CASE WHEN [[agent_enabled]] = 1 THEN 1 ELSE 0 END)'),
                'ready' => new Expression('SUM(CASE WHEN [[vector_store_status]] = :ready THEN 1 ELSE 0 END)'),
            ])
            ->from(self::TABLE)
            ->where(['source_system' => $sourceSystem])
            ->addParams([':ready' => VectorStoreStatus::Ready->value])
            ->one();

        $row = is_array($row) ? $row : [];

        return new SourceKnowledgeBaseCounts(
            total: (int) ($row['total'] ?? 0),
            agentEnabled: (int) ($row['agent_enabled'] ?? 0),
            ready: (int) ($row['ready'] ?? 0),
        );
    }
}
