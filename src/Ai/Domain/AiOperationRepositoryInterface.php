<?php

declare(strict_types=1);

namespace App\Ai\Domain;

/**
 * Persistence boundary for the operation ledger.
 *
 * The status-transition methods each perform one atomic write, so the ledger is durable at every step —
 * in particular, `beginInFlight` commits the in-flight marker before the provider request is made, which
 * is what makes a crash mid-request detectable and reconcilable afterwards.
 */
interface AiOperationRepositoryInterface
{
    public function findByKey(string $operationKey): ?AiOperation;

    /**
     * Records that an operation is about to run: upsert by key, set status to in_flight, increment the
     * attempt count, and stamp the start time. Returns the persisted idempotency key (a new one for a
     * fresh operation, the existing one on a retry).
     */
    public function beginInFlight(
        string $operationKey,
        string $type,
        string $subjectType,
        int $subjectId,
        string $requestFingerprint,
        string $idempotencyKey,
    ): void;

    public function markSucceeded(string $operationKey, string $resultId): void;

    /**
     * The failure had no possible side effect; the operation is safe to retry from scratch.
     */
    public function markPending(string $operationKey, ?string $errorCode, ?string $errorMessage): void;

    /**
     * The failure may have taken effect; the operation must be reconciled against the provider before
     * any retry.
     */
    public function markNeedsReconcile(string $operationKey, ?string $errorCode, ?string $errorMessage): void;

    public function markFailed(string $operationKey, ?string $errorCode, ?string $errorMessage): void;

    /**
     * @return list<AiOperation> Operations awaiting reconciliation, oldest first, up to $limit.
     */
    public function findNeedingReconciliation(int $limit): array;
}
