<?php

declare(strict_types=1);

namespace App\Ai\Application\Operation;

use App\Ai\Contract\Exception\AiException;
use App\Ai\Domain\AiOperation;

/**
 * Resolves whether a possibly-effective operation actually created its object on the provider.
 *
 * One strategy per operation type. The reconciler asks the matching strategy to look up the remote side
 * (by metadata, filename token, or direct id) and return the object's id if it exists — meaning the
 * original call did take effect and should be adopted — or null if nothing was created, so the operation
 * can be retried from scratch.
 */
interface ReconciliationStrategyInterface
{
    public function supports(string $type): bool;

    /**
     * @return string|null The existing provider id to adopt, or null if no matching object was found.
     *
     * @throws AiException when the provider itself could not be queried (the operation is left for a
     *                     later reconcile run).
     */
    public function resolve(AiOperation $operation): ?string;
}
