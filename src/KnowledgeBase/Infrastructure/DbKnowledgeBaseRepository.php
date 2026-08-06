<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Infrastructure;

use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBaseStatus;
use App\KnowledgeBase\Domain\VectorStoreStatus;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;

/**
 * MySQL-backed knowledge-base repository. Returns typed entities, never raw rows.
 */
final readonly class DbKnowledgeBaseRepository implements KnowledgeBaseRepositoryInterface
{
    private const TABLE = '{{%knowledge_bases}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function findById(int $id): ?KnowledgeBase
    {
        return $this->hydrate($this->query()->where(['id' => $id])->limit(1)->one());
    }

    public function findBySlug(string $slug): ?KnowledgeBase
    {
        return $this->hydrate($this->query()->where(['slug' => $slug])->limit(1)->one());
    }

    public function slugExists(string $slug): bool
    {
        return (int) $this->query()->where(['slug' => $slug])->count() > 0;
    }

    public function findAll(bool $includeArchived = false, bool $excludeSystem = false): array
    {
        $query = $this->query()->orderBy(['name' => SORT_ASC, 'id' => SORT_ASC]);

        if (!$includeArchived) {
            $query->where(['status' => KnowledgeBaseStatus::Active->value]);
        }
        if ($excludeSystem) {
            $query->andWhere(['purpose' => 'store']);
        }

        $result = [];
        foreach ($query->all() as $row) {
            $kb = $this->hydrate($row);
            if ($kb !== null) {
                $result[] = $kb;
            }
        }

        return $result;
    }

    public function countActive(bool $excludeSystem = false): int
    {
        $query = $this->query()->where(['status' => KnowledgeBaseStatus::Active->value]);
        if ($excludeSystem) {
            $query->andWhere(['purpose' => 'store']);
        }

        return (int) $query->count();
    }

    public function create(string $name, string $slug, ?string $description, ?string $systemInstructions): int
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->insert(self::TABLE, [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'system_instructions' => $systemInstructions,
            // New knowledge bases start pending; the background worker provisions the vector store.
            'vector_store_status' => VectorStoreStatus::Pending->value,
            'status' => KnowledgeBaseStatus::Active->value,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function createSystem(string $name, string $slug, string $purpose, string $sourceSystem): int
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->insert(self::TABLE, [
            'name' => $name,
            'slug' => $slug,
            'purpose' => $purpose,
            // A system base is not an Order58 store: no store source, and it never appears in the agent
            // directory (agent_enabled = 0). The provisioning drainer still builds its vector store because
            // source_store_id is NULL.
            'source_system' => $sourceSystem,
            'source_store_id' => null,
            'agent_enabled' => 0,
            'vector_store_status' => VectorStoreStatus::Pending->value,
            'status' => KnowledgeBaseStatus::Active->value,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function updateDetails(int $id, string $name, ?string $description, ?string $systemInstructions): void
    {
        // Slug is intentionally not updatable: it is a stable public identifier.
        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'name' => $name,
                'description' => $description,
                'system_instructions' => $systemInstructions,
                'updated_at' => DbDateTime::format($this->clock->now()),
            ],
            ['id' => $id],
        )->execute();
    }

    public function updateStatus(int $id, KnowledgeBaseStatus $status): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            ['status' => $status->value, 'updated_at' => DbDateTime::format($this->clock->now())],
            ['id' => $id],
        )->execute();
    }

    private function query(): \Yiisoft\Db\Query\QueryInterface
    {
        return $this->connection->createQuery()->from(self::TABLE);
    }

    private function hydrate(array|object|null $row): ?KnowledgeBase
    {
        if (!is_array($row)) {
            return null;
        }

        return new KnowledgeBase(
            id: (int) $row['id'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            description: self::nullableString($row['description']),
            systemInstructions: self::nullableString($row['system_instructions']),
            openaiVectorStoreId: self::nullableString($row['openai_vector_store_id']),
            vectorStoreStatus: VectorStoreStatus::from((string) $row['vector_store_status']),
            vectorStoreError: self::nullableString($row['vector_store_error']),
            status: KnowledgeBaseStatus::from((string) $row['status']),
            createdAt: DbDateTime::parse((string) $row['created_at']),
            updatedAt: DbDateTime::parse((string) $row['updated_at']),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
