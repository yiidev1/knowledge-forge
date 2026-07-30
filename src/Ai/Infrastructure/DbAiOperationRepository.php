<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use App\Ai\Domain\AiOperation;
use App\Ai\Domain\AiOperationRepositoryInterface;
use App\Ai\Domain\AiOperationStatus;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;

use function is_array;

/**
 * MySQL-backed operation ledger.
 */
final readonly class DbAiOperationRepository implements AiOperationRepositoryInterface
{
    private const TABLE = '{{%ai_operations}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function findByKey(string $operationKey): ?AiOperation
    {
        return $this->hydrate(
            $this->connection->createQuery()->from(self::TABLE)->where(['operation_key' => $operationKey])->limit(1)->one(),
        );
    }

    public function beginInFlight(
        string $operationKey,
        string $type,
        string $subjectType,
        int $subjectId,
        string $requestFingerprint,
        string $idempotencyKey,
    ): void {
        $now = DbDateTime::format($this->clock->now());
        $existing = $this->findByKey($operationKey);

        if ($existing === null) {
            $this->connection->createCommand()->insert(self::TABLE, [
                'operation_key' => $operationKey,
                'type' => $type,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'status' => AiOperationStatus::InFlight->value,
                'request_fingerprint' => $requestFingerprint,
                'idempotency_key' => $idempotencyKey,
                'attempts' => 1,
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();

            return;
        }

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => AiOperationStatus::InFlight->value,
                'request_fingerprint' => $requestFingerprint,
                'idempotency_key' => $idempotencyKey,
                'attempts' => new Expression('attempts + 1'),
                'started_at' => $now,
                'updated_at' => $now,
            ],
            ['operation_key' => $operationKey],
        )->execute();
    }

    public function markSucceeded(string $operationKey, string $resultId): void
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => AiOperationStatus::Succeeded->value,
                'result_id' => $resultId,
                'last_error_code' => null,
                'last_error_message' => null,
                'next_attempt_at' => null,
                'completed_at' => $now,
                'updated_at' => $now,
            ],
            ['operation_key' => $operationKey],
        )->execute();
    }

    public function markPending(string $operationKey, ?string $errorCode, ?string $errorMessage): void
    {
        $this->markError($operationKey, AiOperationStatus::Pending, $errorCode, $errorMessage);
    }

    public function markNeedsReconcile(string $operationKey, ?string $errorCode, ?string $errorMessage): void
    {
        $this->markError($operationKey, AiOperationStatus::NeedsReconcile, $errorCode, $errorMessage);
    }

    public function markFailed(string $operationKey, ?string $errorCode, ?string $errorMessage): void
    {
        $this->markError($operationKey, AiOperationStatus::Failed, $errorCode, $errorMessage);
    }

    public function findNeedingReconciliation(int $limit): array
    {
        $rows = $this->connection
            ->createQuery()
            ->from(self::TABLE)
            ->where(['status' => AiOperationStatus::NeedsReconcile->value])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $operation = $this->hydrate($row);
            if ($operation !== null) {
                $result[] = $operation;
            }
        }

        return $result;
    }

    private function markError(string $operationKey, AiOperationStatus $status, ?string $errorCode, ?string $errorMessage): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => $status->value,
                'last_error_code' => $errorCode,
                'last_error_message' => $errorMessage,
                'updated_at' => DbDateTime::format($this->clock->now()),
            ],
            ['operation_key' => $operationKey],
        )->execute();
    }

    private function hydrate(array|object|null $row): ?AiOperation
    {
        if (!is_array($row)) {
            return null;
        }

        return new AiOperation(
            id: (int) $row['id'],
            operationKey: (string) $row['operation_key'],
            type: (string) $row['type'],
            subjectType: (string) $row['subject_type'],
            subjectId: (int) $row['subject_id'],
            status: AiOperationStatus::from((string) $row['status']),
            requestFingerprint: self::nullableString($row['request_fingerprint']),
            idempotencyKey: self::nullableString($row['idempotency_key']),
            resultId: self::nullableString($row['result_id']),
            attempts: (int) $row['attempts'],
            nextAttemptAt: DbDateTime::parseNullable(self::nullableString($row['next_attempt_at'])),
            lastErrorCode: self::nullableString($row['last_error_code']),
            lastErrorMessage: self::nullableString($row['last_error_message']),
            startedAt: DbDateTime::parseNullable(self::nullableString($row['started_at'])),
            completedAt: DbDateTime::parseNullable(self::nullableString($row['completed_at'])),
            createdAt: DbDateTime::parse((string) $row['created_at']),
            updatedAt: DbDateTime::parse((string) $row['updated_at']),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
