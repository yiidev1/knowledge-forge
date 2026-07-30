<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Application;

use App\Ai\OpenAi\OpenAiCredentials;
use App\KnowledgeBase\Domain\KnowledgeBaseProvisioningRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Worker\Application\DrainerInterface;
use App\Worker\Application\DrainResult;
use App\Worker\Application\WorkerParams;

/**
 * Drains pending knowledge bases into provisioned vector stores.
 *
 * If OpenAI is not configured it does nothing — it will not burn provisioning attempts on a create that
 * cannot possibly succeed. It claims each candidate atomically so a second worker cannot double-provision,
 * then hands off to {@see ProvisionKnowledgeBaseService}.
 */
final readonly class KnowledgeBaseProvisioningDrainer implements DrainerInterface
{
    public function __construct(
        private KnowledgeBaseProvisioningRepositoryInterface $repository,
        private ProvisionKnowledgeBaseService $service,
        private OpenAiCredentials $credentials,
        private ClockInterface $clock,
        private WorkerParams $params,
    ) {}

    public function name(): string
    {
        return 'kb-provisioning';
    }

    public function recover(): void
    {
        $threshold = $this->clock->now()->modify('-' . $this->params->processingTimeoutMinutes . ' minutes');
        $this->repository->recoverStuck($threshold, $this->clock->now());
    }

    public function drain(int $limit): DrainResult
    {
        if (!$this->credentials->isComplete()) {
            return DrainResult::nothing();
        }

        $processed = 0;
        $failed = 0;

        foreach ($this->repository->findProvisionable($limit, $this->clock->now()) as $candidate) {
            if (!$this->repository->claim($candidate->id, $this->clock->now())) {
                continue; // another worker took it
            }

            // Re-read the incremented attempt count into the candidate for the failure/backoff decision.
            $claimed = new \App\KnowledgeBase\Domain\ProvisioningCandidate(
                $candidate->id,
                $candidate->name,
                $candidate->slug,
                $candidate->attempts + 1,
            );

            if ($this->service->provision($claimed)) {
                $processed++;
            }
        }

        return new DrainResult($processed, $failed);
    }
}
