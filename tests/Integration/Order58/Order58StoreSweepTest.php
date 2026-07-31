<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Domain\StoreMirror;
use App\Order58\Infrastructure\DbOrder58StoreRepository;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;

/**
 * Validates the mark-and-sweep against real MySQL: an unchanged record marked seen stays active, and the
 * NULL-safe deactivation predicate deactivates both stale-marker and never-stamped (NULL) rows. Sentinel
 * source ids keep the shared dev database undisturbed. Skipped when no database is configured.
 */
final class Order58StoreSweepTest extends Unit
{
    private const SEEN = 900000001;
    private const STALE = 900000002;
    private const NULL_MARKER = 900000003;

    private ConnectionInterface $connection;
    private DbOrder58StoreRepository $repository;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->repository = new DbOrder58StoreRepository($this->connection);
        $this->now = new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup(
            $this->connection,
            '{{%order58_stores}}',
            ['source_id' => [self::SEEN, self::STALE, self::NULL_MARKER]],
        );
    }

    private function mirror(int $sourceId): StoreMirror
    {
        return new StoreMirror(
            id: null,
            sourceId: $sourceId,
            name: 'Test ' . $sourceId,
            company: null,
            active: true,
            syncHash: 'h' . $sourceId,
            sourceUpdatedAt: null,
            snapshot: ['id' => $sourceId, 'name' => 'Test', 'active' => true, 'fields' => []],
        );
    }

    private function activeOf(int $sourceId): int
    {
        return (int) $this->connection
            ->createQuery()
            ->select('active')
            ->from('{{%order58_stores}}')
            ->where(['source_id' => $sourceId])
            ->scalar();
    }

    public function testUnchangedRecordMarkedSeenStaysActiveAfterSweep(): void
    {
        // Saved by an earlier run, then only marked seen (the unchanged path) by the current run 200.
        $this->repository->save($this->mirror(self::SEEN), 100, $this->now);
        $this->repository->markSeen(self::SEEN, 200, $this->now);

        $deactivated = $this->repository->deactivateNotSeen(200, $this->now);

        assertNotContains(self::SEEN, $deactivated);
        assertSame(1, $this->activeOf(self::SEEN), 'a record seen this run must remain active');
        // The unchanged path did not rewrite the business fields — the sync hash is untouched.
        assertSame('h' . self::SEEN, $this->repository->findSyncHash(self::SEEN));
    }

    public function testNullSafeSweepDeactivatesStaleAndNullMarkers(): void
    {
        $this->repository->save($this->mirror(self::SEEN), 200, $this->now);   // seen this run
        $this->repository->save($this->mirror(self::STALE), 100, $this->now);  // seen a prior run only

        // A row whose marker was never set (NULL) — inserted directly, as a legacy/first-run row would be.
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => self::NULL_MARKER,
            'name' => 'Null marker',
            'active' => 1,
            'sync_hash' => 'hn',
            'synced_at' => $ts,
            'last_seen_sync_run_id' => null,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        $deactivated = $this->repository->deactivateNotSeen(200, $this->now);

        assertNotContains(self::SEEN, $deactivated);
        assertContains(self::STALE, $deactivated);
        assertContains(self::NULL_MARKER, $deactivated, 'a NULL marker must be treated as not-seen');
        assertSame(1, $this->activeOf(self::SEEN));
        assertSame(0, $this->activeOf(self::STALE));
        assertSame(0, $this->activeOf(self::NULL_MARKER));
    }
}
