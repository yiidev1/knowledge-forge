<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Reconciliation;

use App\Ai\Application\Operation\OperationTypes;
use App\Ai\Application\Operation\ReconciliationStrategyInterface;
use App\Ai\Domain\AiOperation;
use App\Ai\OpenAi\Client\OpenAiClientInterface;

/**
 * Reconciles a vector-store creation whose response was lost.
 *
 * A store is created with its operation key stamped into metadata, so this strategy can list vector
 * stores and adopt the one bearing that key — proving the create took effect and avoiding a duplicate.
 * If none carries the key, nothing was created and the operation can be retried cleanly.
 */
final readonly class VectorStoreCreateReconciliation implements ReconciliationStrategyInterface
{
    public function __construct(
        private OpenAiClientInterface $client,
    ) {}

    public function supports(string $type): bool
    {
        return $type === OperationTypes::VECTOR_STORE_CREATE;
    }

    public function resolve(AiOperation $operation): ?string
    {
        foreach ($this->client->listVectorStores(100) as $store) {
            if (($store->metadata[OperationTypes::METADATA_OPERATION_KEY] ?? null) === $operation->operationKey) {
                return $store->id;
            }
        }

        return null;
    }
}
