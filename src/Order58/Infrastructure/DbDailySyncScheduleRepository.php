<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\DailySyncScheduleRepositoryInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;

use function is_array;

/**
 * MySQL-backed daily-sync reservations. The `UNIQUE(sync_type, ny_date)` index makes {@see reserve()} the
 * atomic per-day gate: a second insert for the same NY date fails and returns null.
 */
final readonly class DbDailySyncScheduleRepository implements DailySyncScheduleRepositoryInterface
{
    private const TABLE = '{{%order58_daily_sync_schedules}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function find(string $syncType, string $nyDate): ?array
    {
        $row = $this->connection->createQuery()
            ->select(['id', 'status', 'integration_sync_run_id'])
            ->from(self::TABLE)
            ->where(['sync_type' => $syncType, 'ny_date' => $nyDate])
            ->limit(1)
            ->one();

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'integration_sync_run_id' => $row['integration_sync_run_id'] === null ? null : (int) $row['integration_sync_run_id'],
        ];
    }

    public function reserve(string $syncType, string $nyDate, DateTimeImmutable $now): ?int
    {
        $ts = DbDateTime::format($now);
        try {
            $this->connection->createCommand()->insert(self::TABLE, [
                'sync_type' => $syncType,
                'ny_date' => $nyDate,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => $ts,
                'updated_at' => $ts,
            ])->execute();
        } catch (IntegrityException) {
            // A concurrent scheduler already reserved this (sync_type, ny_date).
            return null;
        }

        return (int) $this->connection->getLastInsertID();
    }

    public function markEnqueued(int $id, ?int $runId, DateTimeImmutable $now): void
    {
        $this->connection->createCommand()->update(self::TABLE, [
            'status' => 'enqueued',
            'integration_sync_run_id' => $runId,
            'updated_at' => DbDateTime::format($now),
        ], ['id' => $id])->execute();
    }

    public function markFailed(int $id, string $error, DateTimeImmutable $now): void
    {
        $this->connection->createCommand(
            'UPDATE ' . self::TABLE . ' SET [[status]] = :s, [[attempts]] = [[attempts]] + 1,'
            . ' [[last_error]] = :e, [[updated_at]] = :t WHERE [[id]] = :id',
            [':s' => 'failed', ':e' => $error, ':t' => DbDateTime::format($now), ':id' => $id],
        )->execute();
    }
}
