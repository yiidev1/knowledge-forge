<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\Order58SyncType;
use App\Order58\Domain\SyncProgress;
use App\Order58\Domain\SyncRun;
use App\Order58\Domain\SyncRunRepositoryInterface;
use App\Order58\Domain\SyncRunStatus;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Expression\Expression;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * MySQL-backed synchronization state machine.
 */
final readonly class DbSyncRunRepository implements SyncRunRepositoryInterface
{
    private const TABLE = '{{%integration_sync_runs}}';
    private const ACTIVE = [SyncRunStatus::Pending->value, SyncRunStatus::Running->value];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function enqueue(
        Order58SyncType $type,
        ?int $scopeRef,
        ?int $requestedByAdminId,
        DateTimeImmutable $now,
    ): ?int {
        $ts = DbDateTime::format($now);

        try {
            $this->connection->createCommand()->insert(self::TABLE, [
                'type' => $type->value,
                'scope_ref' => $scopeRef,
                'status' => SyncRunStatus::Pending->value,
                'attempts' => 0,
                'requested_by_admin_id' => $requestedByAdminId,
                'progress_json' => (string) json_encode((new SyncProgress())->toArray()),
                'created_at' => $ts,
                'updated_at' => $ts,
            ])->execute();
        } catch (IntegrityException) {
            // The unique `active_key` rejected a duplicate: an active run of this operation already exists.
            return null;
        }

        return (int) $this->connection->getLastInsertId();
    }

    public function hasActive(Order58SyncType $type, ?int $scopeRef): bool
    {
        return (int) $this->connection
            ->createQuery()
            ->from(self::TABLE)
            ->where(['type' => $type->value, 'status' => self::ACTIVE, 'scope_ref' => $scopeRef])
            ->count() > 0;
    }

    public function findClaimable(int $limit, DateTimeImmutable $now): array
    {
        $rows = $this->connection
            ->createQuery()
            ->from(self::TABLE)
            ->where(['status' => self::ACTIVE])
            ->andWhere(['or', ['next_attempt_at' => null], ['<=', 'next_attempt_at', DbDateTime::format($now)]])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();

        $runs = [];
        foreach ($rows as $row) {
            $run = $this->hydrate($row);
            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    public function claim(int $id, DateTimeImmutable $now): bool
    {
        $ts = DbDateTime::format($now);

        // Claims a pending run or re-stamps a running one it is resuming; fails only if the row went
        // terminal. A single worker is guaranteed by the flock, so this is defence in depth.
        $affected = $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => SyncRunStatus::Running->value,
                'started_at' => new Expression('COALESCE(started_at, :ts)', [':ts' => $ts]),
                'claimed_at' => $ts,
                'updated_at' => $ts,
            ],
            ['id' => $id, 'status' => self::ACTIVE],
        )->execute();

        return $affected === 1;
    }

    public function saveProgress(int $id, SyncProgress $progress, DateTimeImmutable $now): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'progress_json' => (string) json_encode($progress->toArray()),
                'claimed_at' => DbDateTime::format($now),
                'updated_at' => DbDateTime::format($now),
            ],
            ['id' => $id],
        )->execute();
    }

    public function finish(
        int $id,
        SyncRunStatus $status,
        SyncProgress $progress,
        ?string $errorCode,
        ?string $errorMessage,
        DateTimeImmutable $now,
    ): void {
        $ts = DbDateTime::format($now);

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => $status->value,
                'progress_json' => (string) json_encode($progress->toArray()),
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'completed_at' => $ts,
                'next_attempt_at' => null,
                'updated_at' => $ts,
            ],
            ['id' => $id],
        )->execute();
    }

    public function requeue(
        int $id,
        DateTimeImmutable $nextAttemptAt,
        ?string $errorCode,
        ?string $errorMessage,
        DateTimeImmutable $now,
    ): void {
        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => SyncRunStatus::Pending->value,
                'attempts' => new Expression('attempts + 1'),
                'next_attempt_at' => DbDateTime::format($nextAttemptAt),
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'updated_at' => DbDateTime::format($now),
            ],
            ['id' => $id],
        )->execute();
    }

    public function recoverStuck(DateTimeImmutable $threshold, DateTimeImmutable $now): int
    {
        return $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => SyncRunStatus::Pending->value,
                'updated_at' => DbDateTime::format($now),
            ],
            ['and', ['status' => SyncRunStatus::Running->value], ['<', 'claimed_at', DbDateTime::format($threshold)]],
        )->execute();
    }

    public function findById(int $id): ?SyncRun
    {
        return $this->hydrate(
            $this->connection->createQuery()->from(self::TABLE)->where(['id' => $id])->limit(1)->one(),
        );
    }

    public function latestByType(): array
    {
        $result = [];
        foreach ([Order58SyncType::Stores, Order58SyncType::Knowledge, Order58SyncType::Agents] as $type) {
            $run = $this->hydrate(
                $this->connection
                    ->createQuery()
                    ->from(self::TABLE)
                    ->where(['type' => $type->value])
                    ->orderBy(['id' => SORT_DESC])
                    ->limit(1)
                    ->one(),
            );
            if ($run !== null) {
                $result[$type->value] = $run;
            }
        }

        return $result;
    }

    public function latestHealth(): ?SyncRun
    {
        return $this->hydrate(
            $this->connection
                ->createQuery()
                ->from(self::TABLE)
                ->where(['type' => Order58SyncType::Health->value])
                ->orderBy(['id' => SORT_DESC])
                ->limit(1)
                ->one(),
        );
    }

    public function recent(int $limit): array
    {
        $rows = $this->connection
            ->createQuery()
            ->from(self::TABLE)
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->all();

        $runs = [];
        foreach ($rows as $row) {
            $run = $this->hydrate($row);
            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    public function countActive(): int
    {
        return (int) $this->connection
            ->createQuery()
            ->from(self::TABLE)
            ->where(['status' => self::ACTIVE])
            ->count();
    }

    private function hydrate(array|object|null $row): ?SyncRun
    {
        if (!is_array($row)) {
            return null;
        }

        $progressRaw = $row['progress_json'] ?? null;
        $progressData = is_string($progressRaw) ? json_decode($progressRaw, true) : null;

        return new SyncRun(
            id: (int) $row['id'],
            type: Order58SyncType::from((string) $row['type']),
            scopeRef: $row['scope_ref'] === null ? null : (int) $row['scope_ref'],
            status: SyncRunStatus::from((string) $row['status']),
            attempts: (int) $row['attempts'],
            requestedByAdminId: $row['requested_by_admin_id'] === null ? null : (int) $row['requested_by_admin_id'],
            progress: SyncProgress::fromArray(is_array($progressData) ? $progressData : null),
            startedAt: DbDateTime::parseNullable(self::nullableString($row['started_at'])),
            completedAt: DbDateTime::parseNullable(self::nullableString($row['completed_at'])),
            errorCode: self::nullableString($row['error_code']),
            errorMessage: self::nullableString($row['error_message']),
            createdAt: DbDateTime::parse((string) $row['created_at']),
            updatedAt: DbDateTime::parse((string) $row['updated_at']),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
