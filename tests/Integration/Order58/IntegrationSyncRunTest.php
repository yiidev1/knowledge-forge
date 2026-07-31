<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Domain\Order58SyncType;
use App\Order58\Domain\SyncProgress;
use App\Order58\Domain\SyncRunStatus;
use App\Order58\Infrastructure\DbSyncRunRepository;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Validates the coalescing generated column and the atomic claim against a real MySQL, keyed by a sentinel
 * scope so the shared dev database is never disturbed. Skipped when no database is configured.
 */
final class IntegrationSyncRunTest extends Unit
{
    private const SCOPE = 987654321;

    private ConnectionInterface $connection;
    private DbSyncRunRepository $repository;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->repository = new DbSyncRunRepository($this->connection);
        $this->now = new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%integration_sync_runs}}', ['scope_ref' => self::SCOPE]);
    }

    public function testDuplicateActiveRunIsCoalesced(): void
    {
        $first = $this->repository->enqueue(Order58SyncType::KnowledgeStore, self::SCOPE, null, $this->now);
        assertNotNull($first);

        // A second click of the same operation while one is active is rejected by the unique active_key.
        $second = $this->repository->enqueue(Order58SyncType::KnowledgeStore, self::SCOPE, null, $this->now);
        assertNull($second);
        assertTrue($this->repository->hasActive(Order58SyncType::KnowledgeStore, self::SCOPE));

        // Once terminal, active_key becomes NULL and the operation can be queued again.
        $this->repository->finish($first, SyncRunStatus::Completed, new SyncProgress(), null, null, $this->now);
        assertFalse($this->repository->hasActive(Order58SyncType::KnowledgeStore, self::SCOPE));

        $third = $this->repository->enqueue(Order58SyncType::KnowledgeStore, self::SCOPE, null, $this->now);
        assertNotNull($third);
    }

    public function testClaimTransitionsPendingToRunningThenFinishes(): void
    {
        $id = $this->repository->enqueue(Order58SyncType::KnowledgeStore, self::SCOPE, null, $this->now);
        assertNotNull($id);

        assertTrue($this->repository->claim($id, $this->now));
        assertSame(SyncRunStatus::Running, $this->repository->findById($id)?->status());

        $this->repository->finish($id, SyncRunStatus::CompletedWithWarnings, new SyncProgress(warnings: 1), 'w', 'Run Sync Stores first.', $this->now);
        $run = $this->repository->findById($id);
        assertSame(SyncRunStatus::CompletedWithWarnings, $run?->status());
        assertSame(1, $run?->progress()->warnings);
    }
}
