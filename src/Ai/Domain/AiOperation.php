<?php

declare(strict_types=1);

namespace App\Ai\Domain;

use DateTimeImmutable;

/**
 * A ledger entry for one non-idempotent OpenAI operation.
 */
final readonly class AiOperation
{
    public function __construct(
        public int $id,
        public string $operationKey,
        public string $type,
        public string $subjectType,
        public int $subjectId,
        public AiOperationStatus $status,
        public ?string $requestFingerprint,
        public ?string $idempotencyKey,
        public ?string $resultId,
        public int $attempts,
        public ?DateTimeImmutable $nextAttemptAt,
        public ?string $lastErrorCode,
        public ?string $lastErrorMessage,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function isSucceeded(): bool
    {
        return $this->status === AiOperationStatus::Succeeded;
    }
}
