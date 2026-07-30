<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ai;

use App\Ai\Domain\AiOperationStatus;
use App\Ai\Infrastructure\DbAiOperationRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

/**
 * Exercises the operation ledger against a real database. Skipped when none is configured.
 */
final class DbAiOperationRepositoryTest extends Unit
{
    private const KEY = '__kf_test_op__:kb:1';

    private ConnectionInterface $connection;
    private DbAiOperationRepository $repository;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->repository = new DbAiOperationRepository($this->connection, new SystemClock());
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testBeginInFlightInsertsThenIncrementsOnRetry(): void
    {
        $this->repository->beginInFlight(self::KEY, 'vs.create', 'kb', 1, 'fp', 'idem-1');

        $op = $this->repository->findByKey(self::KEY);
        assertSame(AiOperationStatus::InFlight, $op?->status);
        assertSame(1, $op?->attempts);
        assertSame('idem-1', $op?->idempotencyKey);

        // Same key again: update in place, attempts increment, no duplicate row.
        $this->repository->beginInFlight(self::KEY, 'vs.create', 'kb', 1, 'fp2', 'idem-1');
        assertSame(2, $this->repository->findByKey(self::KEY)?->attempts);
    }

    public function testMarkSucceededStoresResultId(): void
    {
        $this->repository->beginInFlight(self::KEY, 'vs.create', 'kb', 1, 'fp', 'idem-1');

        $this->repository->markSucceeded(self::KEY, 'vs-123');

        $op = $this->repository->findByKey(self::KEY);
        assertSame(AiOperationStatus::Succeeded, $op?->status);
        assertSame('vs-123', $op?->resultId);
    }

    public function testNeedsReconcileIsListed(): void
    {
        $this->repository->beginInFlight(self::KEY, 'vs.create', 'kb', 1, 'fp', 'idem-1');
        $this->repository->markNeedsReconcile(self::KEY, 'server_error', 'boom');

        $pending = $this->repository->findNeedingReconciliation(10);
        $mine = array_filter($pending, static fn($op) => $op->operationKey === self::KEY);

        assertCount(1, $mine);
        assertSame(AiOperationStatus::NeedsReconcile, $this->repository->findByKey(self::KEY)?->status);
    }

    public function testMarkPendingClearsFromReconcileQueue(): void
    {
        $this->repository->beginInFlight(self::KEY, 'vs.create', 'kb', 1, 'fp', 'idem-1');
        $this->repository->markNeedsReconcile(self::KEY, 'e', 'm');
        $this->repository->markPending(self::KEY, null, null);

        $mine = array_filter($this->repository->findNeedingReconciliation(10), static fn($op) => $op->operationKey === self::KEY);
        assertCount(0, $mine);
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%ai_operations}}', ['operation_key' => self::KEY]);
    }
}
