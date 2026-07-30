<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Ai;

use App\Ai\Domain\AiOperation;
use App\Ai\Domain\AiOperationRepositoryInterface;
use App\Ai\Domain\AiOperationStatus;
use DateTimeImmutable;
use DateTimeZone;

/**
 * In-memory operation ledger for unit tests of the reliable-operation runner and the reconciler.
 */
final class InMemoryAiOperationRepository implements AiOperationRepositoryInterface
{
    /** @var array<string, AiOperation> */
    private array $items = [];

    private int $nextId = 1;

    public function seed(AiOperation $operation): void
    {
        $this->items[$operation->operationKey] = $operation;
    }

    public function findByKey(string $operationKey): ?AiOperation
    {
        return $this->items[$operationKey] ?? null;
    }

    public function beginInFlight(
        string $operationKey,
        string $type,
        string $subjectType,
        int $subjectId,
        string $requestFingerprint,
        string $idempotencyKey,
    ): void {
        $existing = $this->items[$operationKey] ?? null;

        $this->items[$operationKey] = $this->make(
            $existing?->id ?? $this->nextId++,
            $operationKey,
            $type,
            $subjectType,
            $subjectId,
            AiOperationStatus::InFlight,
            $requestFingerprint,
            $idempotencyKey,
            $existing?->resultId,
            ($existing?->attempts ?? 0) + 1,
        );
    }

    public function markSucceeded(string $operationKey, string $resultId): void
    {
        $this->transition($operationKey, AiOperationStatus::Succeeded, $resultId, null, null);
    }

    public function markPending(string $operationKey, ?string $errorCode, ?string $errorMessage): void
    {
        $this->transition($operationKey, AiOperationStatus::Pending, null, $errorCode, $errorMessage);
    }

    public function markNeedsReconcile(string $operationKey, ?string $errorCode, ?string $errorMessage): void
    {
        $this->transition($operationKey, AiOperationStatus::NeedsReconcile, null, $errorCode, $errorMessage);
    }

    public function markFailed(string $operationKey, ?string $errorCode, ?string $errorMessage): void
    {
        $this->transition($operationKey, AiOperationStatus::Failed, null, $errorCode, $errorMessage);
    }

    public function findNeedingReconciliation(int $limit): array
    {
        $result = [];
        foreach ($this->items as $operation) {
            if ($operation->status === AiOperationStatus::NeedsReconcile) {
                $result[] = $operation;
            }
        }

        return array_slice($result, 0, $limit);
    }

    private function transition(string $key, AiOperationStatus $status, ?string $resultId, ?string $code, ?string $message): void
    {
        $existing = $this->items[$key] ?? null;
        if ($existing === null) {
            return;
        }

        $this->items[$key] = $this->make(
            $existing->id,
            $existing->operationKey,
            $existing->type,
            $existing->subjectType,
            $existing->subjectId,
            $status,
            $existing->requestFingerprint,
            $existing->idempotencyKey,
            $resultId ?? $existing->resultId,
            $existing->attempts,
            $code,
            $message,
        );
    }

    private function make(
        int $id,
        string $key,
        string $type,
        string $subjectType,
        int $subjectId,
        AiOperationStatus $status,
        ?string $fingerprint,
        ?string $idempotencyKey,
        ?string $resultId,
        int $attempts,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): AiOperation {
        $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        return new AiOperation(
            $id,
            $key,
            $type,
            $subjectType,
            $subjectId,
            $status,
            $fingerprint,
            $idempotencyKey,
            $resultId,
            $attempts,
            null,
            $errorCode,
            $errorMessage,
            $now,
            null,
            $now,
            $now,
        );
    }
}
