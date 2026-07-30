<?php

declare(strict_types=1);

namespace App\Tests\Unit\KnowledgeBase;

use App\Ai\Application\Operation\ReliableOperation;
use App\Ai\Contract\Dto\AiErrorDetails;
use App\Ai\Contract\Exception\AiProcessingFailed;
use App\Ai\Contract\Exception\AiTimeout;
use App\KnowledgeBase\Application\ProvisionKnowledgeBaseService;
use App\KnowledgeBase\Domain\ProvisioningCandidate;
use App\Tests\Support\Fake\Ai\FakeKnowledgeIndex;
use App\Tests\Support\Fake\Ai\InMemoryAiOperationRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseProvisioningRepository;
use App\Tests\Support\MutableClock;
use App\Worker\Application\WorkerParams;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Provisioning a knowledge base's vector store: success marks it ready with the store id; a transient
 * failure requeues it with backoff; an unrecoverable failure, or one past the attempt cap, fails it.
 */
final class ProvisionKnowledgeBaseServiceTest extends Unit
{
    private InMemoryKnowledgeBaseProvisioningRepository $repository;
    private FakeKnowledgeIndex $index;
    private MutableClock $clock;

    protected function _before(): void
    {
        $this->repository = new InMemoryKnowledgeBaseProvisioningRepository();
        $this->index = new FakeKnowledgeIndex();
        $this->clock = new MutableClock();
    }

    public function testSuccessMarksReadyWithTheStoreId(): void
    {
        $this->repository->seed(1, 'HR', 'hr');

        $terminal = $this->service()->provision(new ProvisioningCandidate(1, 'HR', 'hr', 1));

        assertTrue($terminal);
        assertSame('ready', $this->repository->statusOf(1));
        assertNotNull($this->repository->vectorStoreIdOf(1));
    }

    public function testTransientFailureRequeuesWithBackoff(): void
    {
        $this->repository->seed(1, 'HR', 'hr');
        $this->index->throwOn('createStore', new AiTimeout(AiErrorDetails::of('timeout', 'read timeout', transient: true)));

        $terminal = $this->service()->provision(new ProvisioningCandidate(1, 'HR', 'hr', 1));

        assertFalse($terminal);
        assertSame('pending', $this->repository->statusOf(1));
        $next = $this->repository->nextAttemptAtOf(1);
        assertNotNull($next);
        assertGreaterThan($this->clock->now(), $next);
    }

    public function testUnrecoverableFailureFailsImmediately(): void
    {
        $this->repository->seed(1, 'HR', 'hr');
        $this->index->throwOn('createStore', new AiProcessingFailed(AiErrorDetails::of('invalid_request', 'bad name', transient: false)));

        $terminal = $this->service()->provision(new ProvisioningCandidate(1, 'HR', 'hr', 1));

        assertTrue($terminal);
        assertSame('failed', $this->repository->statusOf(1));
    }

    public function testTransientFailureAtAttemptCapFails(): void
    {
        $this->repository->seed(1, 'HR', 'hr');
        $this->index->throwOn('createStore', new AiTimeout(AiErrorDetails::of('timeout', 'read timeout', transient: true)));

        // provisionMaxAttempts=5; this candidate is already at 5 → give up.
        $terminal = $this->service()->provision(new ProvisioningCandidate(1, 'HR', 'hr', 5));

        assertTrue($terminal);
        assertSame('failed', $this->repository->statusOf(1));
    }

    private function service(): ProvisionKnowledgeBaseService
    {
        return new ProvisionKnowledgeBaseService(
            $this->repository,
            $this->index,
            new ReliableOperation(new InMemoryAiOperationRepository()),
            $this->clock,
            new WorkerParams(
                batchSize: 1,
                maxProcessingAttempts: 3,
                processingTimeoutMinutes: 20,
                retryBaseSeconds: 60,
                provisionMaxAttempts: 5,
                indexPollIntervalSeconds: 3,
            ),
        );
    }
}
