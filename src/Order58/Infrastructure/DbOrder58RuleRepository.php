<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\Order58RuleRepositoryInterface;
use App\Order58\Domain\RuleMirror;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;
use function is_numeric;
use function is_string;
use function json_encode;

/**
 * MySQL-backed mirror of Order58 rule records. Idempotent by the UNIQUE `source_id`; a disappeared record is
 * soft-deactivated (`is_active = 0`), never deleted.
 */
final readonly class DbOrder58RuleRepository implements Order58RuleRepositoryInterface
{
    private const TABLE = '{{%order58_rule_records}}';

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

    public function findIdBySourceId(int $sourceId): ?int
    {
        $value = $this->connection
            ->createQuery()
            ->select('id')
            ->from(self::TABLE)
            ->where(['source_id' => $sourceId])
            ->scalar();

        return is_numeric($value) ? (int) $value : null;
    }

    public function save(RuleMirror $record, int $runId, DateTimeImmutable $now): void
    {
        $ts = DbDateTime::format($now);

        $insert = [
            'source_id' => $record->sourceId,
            'type' => $record->type,
            'title' => $record->title,
            'description' => $record->description,
            'rule_keyword' => $record->ruleKeyword,
            'created_name' => $record->createdName,
            'source_store_id' => $record->sourceStoreId,
            'is_active' => $record->active ? 1 : 0,
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

    public function markSeen(int $sourceId, int $runId, DateTimeImmutable $now, bool $active = true): bool
    {
        $ts = DbDateTime::format($now);
        $desired = $active ? 1 : 0;

        $current = $this->connection
            ->createQuery()
            ->select('is_active')
            ->from(self::TABLE)
            ->where(['source_id' => $sourceId])
            ->scalar();

        if ($current === false || $current === null) {
            return false;
        }

        $wasActive = (int) $current === 1;
        $activityChanged = $wasActive !== $active;

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'last_seen_sync_run_id' => $runId,
                'is_active' => $desired,
                'synced_at' => $ts,
                'updated_at' => $ts,
            ],
            ['source_id' => $sourceId],
        )->execute();

        return $activityChanged;
    }

    public function deactivateNotSeen(int $runId, DateTimeImmutable $now): array
    {
        $condition = [
            'and',
            ['is_active' => 1],
            ['or', ['last_seen_sync_run_id' => null], ['<>', 'last_seen_sync_run_id', $runId]],
        ];

        $rows = $this->connection
            ->createQuery()
            ->select(['id', 'source_id'])
            ->from(self::TABLE)
            ->where($condition)
            ->all();

        if ($rows === []) {
            return [];
        }

        $this->connection->createCommand()->update(
            self::TABLE,
            ['is_active' => 0, 'updated_at' => DbDateTime::format($now)],
            $condition,
        )->execute();

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = ['source_id' => (int) $row['source_id'], 'record_id' => (int) $row['id']];
            }
        }

        return $result;
    }

    public function countAll(): int
    {
        return (int) $this->connection->createQuery()->from(self::TABLE)->count();
    }

    public function countActive(): int
    {
        return (int) $this->connection->createQuery()->from(self::TABLE)->where(['is_active' => 1])->count();
    }
}
