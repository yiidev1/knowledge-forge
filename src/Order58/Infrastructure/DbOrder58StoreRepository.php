<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Order58\Domain\StoreMirror;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_map;
use function array_values;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * MySQL-backed mirror of Order58 stores.
 */
final readonly class DbOrder58StoreRepository implements Order58StoreRepositoryInterface
{
    private const TABLE = '{{%order58_stores}}';

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

    public function findBySourceId(int $sourceId): ?StoreMirror
    {
        $row = $this->connection
            ->createQuery()
            ->from(self::TABLE)
            ->where(['source_id' => $sourceId])
            ->limit(1)
            ->one();

        if (!is_array($row)) {
            return null;
        }

        $snapshotRaw = $row['snapshot_json'] ?? null;
        $snapshot = is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : null;

        return new StoreMirror(
            id: (int) $row['id'],
            sourceId: (int) $row['source_id'],
            name: (string) $row['name'],
            company: $row['company'] === null ? null : (string) $row['company'],
            active: (bool) (int) $row['active'],
            syncHash: (string) $row['sync_hash'],
            sourceUpdatedAt: DbDateTime::parseNullable($row['source_updated_at'] === null ? null : (string) $row['source_updated_at']),
            snapshot: is_array($snapshot) ? $snapshot : [],
            syncedAt: DbDateTime::parseNullable($row['synced_at'] === null ? null : (string) $row['synced_at']),
        );
    }

    public function save(StoreMirror $store, int $runId, DateTimeImmutable $now): void
    {
        $ts = DbDateTime::format($now);

        $insert = [
            'source_id' => $store->sourceId,
            'name' => $store->name,
            'company' => $store->company,
            'active' => $store->active ? 1 : 0,
            'snapshot_json' => (string) json_encode($store->snapshot),
            'sync_hash' => $store->syncHash,
            'source_updated_at' => $store->sourceUpdatedAt === null ? null : DbDateTime::format($store->sourceUpdatedAt),
            'synced_at' => $ts,
            'last_seen_sync_run_id' => $runId,
            'created_at' => $ts,
            'updated_at' => $ts,
        ];

        $update = $insert;
        unset($update['source_id'], $update['created_at']);

        $this->connection->createCommand()->upsert(self::TABLE, $insert, $update)->execute();
    }

    public function allMirrors(): array
    {
        $rows = $this->connection->createQuery()->from(self::TABLE)->orderBy(['source_id' => SORT_ASC])->all();

        $mirrors = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $snapshotRaw = $row['snapshot_json'] ?? null;
            $snapshot = is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : null;

            $mirrors[] = new StoreMirror(
                id: (int) $row['id'],
                sourceId: (int) $row['source_id'],
                name: (string) $row['name'],
                company: $row['company'] === null ? null : (string) $row['company'],
                active: (bool) (int) $row['active'],
                syncHash: (string) $row['sync_hash'],
                sourceUpdatedAt: DbDateTime::parseNullable($row['source_updated_at'] === null ? null : (string) $row['source_updated_at']),
                snapshot: is_array($snapshot) ? $snapshot : [],
                syncedAt: DbDateTime::parseNullable($row['synced_at'] === null ? null : (string) $row['synced_at']),
            );
        }

        return $mirrors;
    }

    public function setActive(int $sourceId, bool $active, DateTimeImmutable $now): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            ['active' => $active ? 1 : 0, 'updated_at' => DbDateTime::format($now)],
            ['source_id' => $sourceId],
        )->execute();
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
        // NULL-safe: a row whose marker is NULL was never stamped by this run, so it counts as not-seen.
        $condition = [
            'and',
            ['active' => 1],
            ['or', ['last_seen_sync_run_id' => null], ['<>', 'last_seen_sync_run_id', $runId]],
        ];

        $ids = $this->connection->createQuery()->select('source_id')->from(self::TABLE)->where($condition)->column();
        if ($ids === []) {
            return [];
        }

        $this->connection->createCommand()->update(
            self::TABLE,
            ['active' => 0, 'updated_at' => DbDateTime::format($now)],
            $condition,
        )->execute();

        return array_values(array_map(static fn(mixed $id): int => (int) $id, $ids));
    }

    public function countAll(): int
    {
        return (int) $this->connection->createQuery()->from(self::TABLE)->count();
    }

    public function countActive(): int
    {
        return (int) $this->connection->createQuery()->from(self::TABLE)->where(['active' => 1])->count();
    }
}
