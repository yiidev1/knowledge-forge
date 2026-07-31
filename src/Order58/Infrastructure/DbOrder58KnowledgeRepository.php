<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\KnowledgeMirror;
use App\Order58\Domain\Order58KnowledgeRepositoryInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * MySQL-backed mirror of Order58 knowledge records.
 */
final readonly class DbOrder58KnowledgeRepository implements Order58KnowledgeRepositoryInterface
{
    private const TABLE = '{{%order58_knowledge_records}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findSyncHash(int $sourceId): ?string
    {
        $value = $this->connection
            ->createQuery()
            ->select('sync_hash')
            ->from(self::TABLE)
            ->where(['source_id' => $sourceId])
            ->scalar();

        return is_string($value) ? $value : null;
    }

    public function save(KnowledgeMirror $record, int $runId, DateTimeImmutable $now): void
    {
        $ts = DbDateTime::format($now);

        $insert = [
            'source_id' => $record->sourceId,
            'store_source_id' => $record->storeSourceId,
            'title' => $record->title,
            'content' => $record->content,
            'knowledge_number' => $record->knowledgeNumber,
            'keyword' => $record->keyword,
            'type' => $record->type,
            'active' => $record->active ? 1 : 0,
            'snapshot_json' => (string) json_encode($record->snapshot),
            'sync_hash' => $record->syncHash,
            'source_created_at' => $record->sourceCreatedAt === null ? null : DbDateTime::format($record->sourceCreatedAt),
            'source_updated_at' => $record->sourceUpdatedAt === null ? null : DbDateTime::format($record->sourceUpdatedAt),
            'synced_at' => $ts,
            'last_seen_sync_run_id' => $runId,
            'created_at' => $ts,
            'updated_at' => $ts,
        ];

        $update = $insert;
        unset($update['source_id'], $update['created_at']);

        $this->connection->createCommand()->upsert(self::TABLE, $insert, $update)->execute();
    }

    public function markSeen(int $sourceId, int $runId, DateTimeImmutable $now): void
    {
        $ts = DbDateTime::format($now);

        $this->connection->createCommand()->update(
            self::TABLE,
            ['last_seen_sync_run_id' => $runId, 'synced_at' => $ts, 'updated_at' => $ts],
            ['source_id' => $sourceId],
        )->execute();
    }

    public function deactivateNotSeen(int $runId, DateTimeImmutable $now): array
    {
        return $this->deactivate(
            [
                'and',
                ['active' => 1],
                ['or', ['last_seen_sync_run_id' => null], ['<>', 'last_seen_sync_run_id', $runId]],
            ],
            $now,
        );
    }

    public function deactivateNotSeenForStore(int $storeSourceId, int $runId, DateTimeImmutable $now): array
    {
        return $this->deactivate(
            [
                'and',
                ['active' => 1, 'store_source_id' => $storeSourceId],
                ['or', ['last_seen_sync_run_id' => null], ['<>', 'last_seen_sync_run_id', $runId]],
            ],
            $now,
        );
    }

    public function findAllForStore(int $storeSourceId): array
    {
        $rows = $this->connection
            ->createQuery()
            ->from(self::TABLE)
            ->where(['store_source_id' => $storeSourceId, 'active' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $records = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $records[] = $this->hydrate($row);
            }
        }

        return $records;
    }

    public function countAll(): int
    {
        return (int) $this->connection->createQuery()->from(self::TABLE)->count();
    }

    public function countActive(): int
    {
        return (int) $this->connection->createQuery()->from(self::TABLE)->where(['active' => 1])->count();
    }

    /**
     * @param array<array-key, mixed> $condition
     *
     * @return list<array{source_id: int, store_source_id: int}>
     */
    private function deactivate(array $condition, DateTimeImmutable $now): array
    {
        $rows = $this->connection
            ->createQuery()
            ->select(['source_id', 'store_source_id'])
            ->from(self::TABLE)
            ->where($condition)
            ->all();

        if ($rows === []) {
            return [];
        }

        $this->connection->createCommand()->update(
            self::TABLE,
            ['active' => 0, 'updated_at' => DbDateTime::format($now)],
            $condition,
        )->execute();

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = ['source_id' => (int) $row['source_id'], 'store_source_id' => (int) $row['store_source_id']];
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): KnowledgeMirror
    {
        $snapshotRaw = $row['snapshot_json'] ?? null;
        $snapshot = is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : null;

        return new KnowledgeMirror(
            id: (int) $row['id'],
            sourceId: (int) $row['source_id'],
            storeSourceId: (int) $row['store_source_id'],
            title: (string) $row['title'],
            content: (string) $row['content'],
            knowledgeNumber: self::nullableString($row['knowledge_number']),
            keyword: self::nullableString($row['keyword']),
            type: self::nullableString($row['type']),
            active: (bool) (int) $row['active'],
            syncHash: (string) $row['sync_hash'],
            sourceCreatedAt: DbDateTime::parseNullable(self::nullableString($row['source_created_at'])),
            sourceUpdatedAt: DbDateTime::parseNullable(self::nullableString($row['source_updated_at'])),
            snapshot: is_array($snapshot) ? $snapshot : [],
            syncedAt: DbDateTime::parseNullable(self::nullableString($row['synced_at'])),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
