<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Infrastructure;

use App\KnowledgeBase\Domain\KnowledgeBaseProvisioningRepositoryInterface;
use App\KnowledgeBase\Domain\ProvisioningCandidate;
use App\KnowledgeBase\Domain\VectorStoreStatus;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;

use function is_array;

/**
 * MySQL-backed provisioning repository. The claim relies on a conditional UPDATE affecting exactly the
 * one still-pending row, which is what makes concurrent workers safe without table locks.
 */
final readonly class DbKnowledgeBaseProvisioningRepository implements KnowledgeBaseProvisioningRepositoryInterface
{
    private const TABLE = '{{%knowledge_bases}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function findProvisionable(int $limit, DateTimeImmutable $now): array
    {
        $rows = $this->connection
            ->createQuery()
            ->select(['id', 'name', 'slug', 'provision_attempts'])
            ->from(self::TABLE)
            ->where(['vector_store_status' => VectorStoreStatus::Pending->value, 'status' => 'active'])
            // Order58-backed knowledge bases are provisioned only once the source store is active and the
            // knowledge base is enabled — an inactive store keeps its mapping but defers its vector store.
            // Manually-created knowledge bases (no source store) are unaffected.
            ->andWhere(['or', ['source_store_id' => null], ['source_active' => 1, 'agent_enabled' => 1]])
            ->andWhere(['or', ['provision_next_attempt_at' => null], ['<=', 'provision_next_attempt_at', DbDateTime::format($now)]])
            ->orderBy(['provision_next_attempt_at' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit)
            ->all();

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = new ProvisioningCandidate(
                    (int) $row['id'],
                    (string) $row['name'],
                    (string) $row['slug'],
                    (int) $row['provision_attempts'],
                );
            }
        }

        return $result;
    }

    public function claim(int $knowledgeBaseId, DateTimeImmutable $now): bool
    {
        $affected = $this->connection->createCommand()->update(
            self::TABLE,
            [
                'vector_store_status' => VectorStoreStatus::Provisioning->value,
                'provision_attempts' => new Expression('provision_attempts + 1'),
                'provision_started_at' => DbDateTime::format($now),
                'updated_at' => DbDateTime::format($now),
            ],
            ['id' => $knowledgeBaseId, 'vector_store_status' => VectorStoreStatus::Pending->value],
        )->execute();

        return $affected === 1;
    }

    public function markReady(int $knowledgeBaseId, string $vectorStoreId): void
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'vector_store_status' => VectorStoreStatus::Ready->value,
                'openai_vector_store_id' => $vectorStoreId,
                'vector_store_error_code' => null,
                'vector_store_error' => null,
                'provision_next_attempt_at' => null,
                'updated_at' => $now,
            ],
            ['id' => $knowledgeBaseId],
        )->execute();
    }

    public function requeue(int $knowledgeBaseId, DateTimeImmutable $nextAttemptAt, ?string $errorCode, ?string $errorMessage): void
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'vector_store_status' => VectorStoreStatus::Pending->value,
                'provision_next_attempt_at' => DbDateTime::format($nextAttemptAt),
                'vector_store_error_code' => $errorCode,
                'vector_store_error' => $errorMessage,
                'updated_at' => $now,
            ],
            ['id' => $knowledgeBaseId],
        )->execute();
    }

    public function markFailed(int $knowledgeBaseId, ?string $errorCode, ?string $errorMessage): void
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'vector_store_status' => VectorStoreStatus::Failed->value,
                'vector_store_error_code' => $errorCode,
                'vector_store_error' => $errorMessage,
                'provision_next_attempt_at' => null,
                'updated_at' => $now,
            ],
            ['id' => $knowledgeBaseId],
        )->execute();
    }

    public function recoverStuck(DateTimeImmutable $threshold, DateTimeImmutable $now): int
    {
        return $this->connection->createCommand()->update(
            self::TABLE,
            [
                'vector_store_status' => VectorStoreStatus::Pending->value,
                'updated_at' => DbDateTime::format($now),
            ],
            ['and',
                ['vector_store_status' => VectorStoreStatus::Provisioning->value],
                ['<', 'provision_started_at', DbDateTime::format($threshold)],
            ],
        )->execute();
    }
}
