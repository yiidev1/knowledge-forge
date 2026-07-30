<?php

declare(strict_types=1);

namespace App\Ai\Application\Operation;

use App\Ai\Contract\Exception\AiException;
use App\Ai\Domain\AiOperation;
use App\Ai\Domain\AiOperationRepositoryInterface;

/**
 * Resolves operations left in `needs_reconcile` by querying the provider and either adopting the object
 * that was actually created or releasing the operation for a fresh retry.
 *
 * This is what turns an ambiguous failure into a definite outcome: it never creates anything, so running
 * it is always safe. Operations whose type has no strategy, or which have exhausted their attempts, are
 * failed so they do not loop forever. A provider error during reconciliation leaves the operation as-is
 * for the next run.
 */
final readonly class AiOperationReconciler
{
    /**
     * @param list<ReconciliationStrategyInterface> $strategies
     */
    public function __construct(
        private AiOperationRepositoryInterface $ledger,
        private array $strategies,
        private int $maxAttempts,
    ) {}

    /**
     * @return int The number of operations resolved to a terminal state (succeeded or failed).
     */
    public function reconcileBatch(int $limit): int
    {
        $resolved = 0;

        foreach ($this->ledger->findNeedingReconciliation($limit) as $operation) {
            if ($this->reconcile($operation)) {
                $resolved++;
            }
        }

        return $resolved;
    }

    private function reconcile(AiOperation $operation): bool
    {
        $strategy = $this->strategyFor($operation->type);

        if ($strategy === null) {
            $this->ledger->markFailed(
                $operation->operationKey,
                'no_reconciliation_strategy',
                'No way to reconcile operation type "' . $operation->type . '".',
            );

            return true;
        }

        if ($operation->attempts >= $this->maxAttempts) {
            $this->ledger->markFailed(
                $operation->operationKey,
                'reconcile_attempts_exhausted',
                'Gave up reconciling after ' . $operation->attempts . ' attempts.',
            );

            return true;
        }

        try {
            $resultId = $strategy->resolve($operation);
        } catch (AiException) {
            // Could not query the provider; leave it for the next reconcile run.
            return false;
        }

        if ($resultId !== null) {
            // The original call did take effect — adopt the existing object; no duplicate created.
            $this->ledger->markSucceeded($operation->operationKey, $resultId);

            return true;
        }

        // Nothing was created; release for a fresh attempt.
        $this->ledger->markPending(
            $operation->operationKey,
            'reconciled_absent',
            'No matching remote object was found; the operation will be retried.',
        );

        return true;
    }

    private function strategyFor(string $type): ?ReconciliationStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($type)) {
                return $strategy;
            }
        }

        return null;
    }
}
