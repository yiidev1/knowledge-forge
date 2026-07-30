<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Infrastructure;

use App\KnowledgeBase\Domain\Rule;
use App\KnowledgeBase\Domain\RuleRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;

/**
 * MySQL-backed rule repository. Every read and write is scoped to a knowledge base.
 */
final readonly class DbRuleRepository implements RuleRepositoryInterface
{
    private const TABLE = '{{%knowledge_base_rules}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function findByIdForKnowledgeBase(int $ruleId, int $knowledgeBaseId): ?Rule
    {
        return $this->hydrate(
            $this->query()
                ->where(['id' => $ruleId, 'knowledge_base_id' => $knowledgeBaseId])
                ->limit(1)
                ->one(),
        );
    }

    public function findAllForKnowledgeBase(int $knowledgeBaseId): array
    {
        return $this->hydrateAll(
            $this->query()
                ->where(['knowledge_base_id' => $knowledgeBaseId])
                ->orderBy(['priority' => SORT_ASC, 'id' => SORT_ASC])
                ->all(),
        );
    }

    public function findEnabledForKnowledgeBase(int $knowledgeBaseId): array
    {
        return $this->hydrateAll(
            $this->query()
                ->where(['knowledge_base_id' => $knowledgeBaseId, 'is_enabled' => 1])
                ->orderBy(['priority' => SORT_ASC, 'id' => SORT_ASC])
                ->all(),
        );
    }

    public function nameExistsInKnowledgeBase(string $name, int $knowledgeBaseId, ?int $excludingRuleId = null): bool
    {
        $condition = ['knowledge_base_id' => $knowledgeBaseId, 'name' => $name];
        $query = $this->query()->where($condition);

        if ($excludingRuleId !== null) {
            $query->andWhere(['<>', 'id', $excludingRuleId]);
        }

        return (int) $query->count() > 0;
    }

    public function countForKnowledgeBase(int $knowledgeBaseId): int
    {
        return (int) $this->query()->where(['knowledge_base_id' => $knowledgeBaseId])->count();
    }

    public function countsByKnowledgeBase(): array
    {
        $rows = $this->connection
            ->createQuery()
            ->select([
                'knowledge_base_id',
                'total' => 'COUNT(*)',
                'enabled' => 'SUM(is_enabled)',
            ])
            ->from(self::TABLE)
            ->groupBy('knowledge_base_id')
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $counts[(int) $row['knowledge_base_id']] = [
                'total' => (int) $row['total'],
                'enabled' => (int) $row['enabled'],
            ];
        }

        return $counts;
    }

    public function maxPriority(int $knowledgeBaseId): ?int
    {
        $max = $this->query()->where(['knowledge_base_id' => $knowledgeBaseId])->max('priority');

        return $max === null ? null : (int) $max;
    }

    public function create(int $knowledgeBaseId, string $name, string $instruction, int $priority, bool $isEnabled): int
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->insert(self::TABLE, [
            'knowledge_base_id' => $knowledgeBaseId,
            'name' => $name,
            'instruction' => $instruction,
            'priority' => $priority,
            'is_enabled' => $isEnabled ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function update(int $ruleId, string $name, string $instruction): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            ['name' => $name, 'instruction' => $instruction, 'updated_at' => DbDateTime::format($this->clock->now())],
            ['id' => $ruleId],
        )->execute();
    }

    public function setEnabled(int $ruleId, bool $isEnabled): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            ['is_enabled' => $isEnabled ? 1 : 0, 'updated_at' => DbDateTime::format($this->clock->now())],
            ['id' => $ruleId],
        )->execute();
    }

    public function setPriority(int $ruleId, int $priority): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            ['priority' => $priority, 'updated_at' => DbDateTime::format($this->clock->now())],
            ['id' => $ruleId],
        )->execute();
    }

    public function delete(int $ruleId): void
    {
        $this->connection->createCommand()->delete(self::TABLE, ['id' => $ruleId])->execute();
    }

    private function query(): \Yiisoft\Db\Query\QueryInterface
    {
        return $this->connection->createQuery()->from(self::TABLE);
    }

    /**
     * @param array<array-key, array<array-key, mixed>|object> $rows
     *
     * @return list<Rule>
     */
    private function hydrateAll(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $rule = $this->hydrate($row);
            if ($rule !== null) {
                $result[] = $rule;
            }
        }

        return $result;
    }

    private function hydrate(array|object|null $row): ?Rule
    {
        if (!is_array($row)) {
            return null;
        }

        return new Rule(
            id: (int) $row['id'],
            knowledgeBaseId: (int) $row['knowledge_base_id'],
            name: (string) $row['name'],
            instruction: (string) $row['instruction'],
            priority: (int) $row['priority'],
            isEnabled: (bool) (int) $row['is_enabled'],
            createdAt: DbDateTime::parse((string) $row['created_at']),
            updatedAt: DbDateTime::parse((string) $row['updated_at']),
        );
    }
}
