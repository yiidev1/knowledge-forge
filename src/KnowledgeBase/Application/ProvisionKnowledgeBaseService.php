<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Application;

use App\Ai\Application\Operation\OperationTypes;
use App\Ai\Application\Operation\ReliableOperation;
use App\Ai\Contract\Exception\AiException;
use App\Ai\Contract\KnowledgeIndexInterface;
use App\KnowledgeBase\Domain\KnowledgeBaseProvisioningRepositoryInterface;
use App\KnowledgeBase\Domain\ProvisioningCandidate;
use App\Shared\Domain\Clock\ClockInterface;
use App\Worker\Application\WorkerParams;

use function sprintf;

/**
 * Provisions one knowledge base's vector store, exactly once in effect.
 *
 * The create is run through the operation ledger keyed on the knowledge base, and the store is tagged
 * with that operation key in its metadata — so a create whose response was lost is adopted by the
 * reconciler rather than duplicated. A subsequent provisioning run then finds the operation already
 * succeeded and simply records the store id without calling OpenAI again.
 *
 * On failure the knowledge base is returned to `pending` with exponential backoff, or marked `failed`
 * once its attempts are exhausted; the ledger, meanwhile, tracks whether the create was ambiguous.
 */
final readonly class ProvisionKnowledgeBaseService
{
    public function __construct(
        private KnowledgeBaseProvisioningRepositoryInterface $repository,
        private KnowledgeIndexInterface $knowledgeIndex,
        private ReliableOperation $reliableOperation,
        private ClockInterface $clock,
        private WorkerParams $params,
    ) {}

    /**
     * @return bool True if the knowledge base reached a terminal state (ready or failed) this run.
     */
    public function provision(ProvisioningCandidate $candidate): bool
    {
        $operationKey = OperationTypes::key(OperationTypes::VECTOR_STORE_CREATE, 'kb', $candidate->id);
        $name = sprintf('kf-%d-%s', $candidate->id, $candidate->slug);

        try {
            $vectorStoreId = $this->reliableOperation->run(
                $operationKey,
                OperationTypes::VECTOR_STORE_CREATE,
                'kb',
                $candidate->id,
                ['name' => $name],
                fn(): string => $this->knowledgeIndex->createStore(
                    $name,
                    [OperationTypes::METADATA_OPERATION_KEY => $operationKey],
                ),
            );
        } catch (AiException $e) {
            return $this->handleFailure($candidate, $e);
        }

        $this->repository->markReady($candidate->id, $vectorStoreId);

        return true;
    }

    private function handleFailure(ProvisioningCandidate $candidate, AiException $e): bool
    {
        $details = $e->details();

        // A non-transient, non-ambiguous rejection (bad key, invalid request) will not fix itself; fail
        // fast. Otherwise retry with backoff until the attempt cap, then give up.
        $unrecoverable = !$details->transient && !$details->sideEffectPossible;

        if ($unrecoverable || $candidate->attempts >= $this->params->provisionMaxAttempts) {
            $this->repository->markFailed($candidate->id, $details->code, $details->safeMessage);

            return true;
        }

        $nextAttempt = $this->clock->now()->modify('+' . $this->params->backoffSeconds($candidate->attempts) . ' seconds');
        $this->repository->requeue($candidate->id, $nextAttempt, $details->code, $details->safeMessage);

        return false;
    }
}
