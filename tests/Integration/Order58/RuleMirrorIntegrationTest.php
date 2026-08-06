<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Domain\RuleMirror;
use App\Order58\Infrastructure\DbOrder58RuleRepository;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_column;
use function str_repeat;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

/**
 * The Order58 rule mirror against real MySQL: idempotent upsert by source_id, the sync-hash lookup, and the
 * NULL-safe mark-and-sweep (which returns each deactivated record's id so its canonical can be recomputed).
 * Sentinel source ids keep the shared dev database undisturbed; skipped when no database is configured.
 */
final class RuleMirrorIntegrationTest extends Unit
{
    private const SEEN = 970000001;
    private const STALE = 970000002;

    private ConnectionInterface $connection;
    private DbOrder58RuleRepository $repository;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->repository = new DbOrder58RuleRepository($this->connection);
        $this->now = new DateTimeImmutable('2026-08-05 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%order58_rule_records}}', ['source_id' => [self::SEEN, self::STALE]]);
    }

    public function testUpsertIsIdempotentBySourceIdAndTracksTheSyncHash(): void
    {
        $this->repository->save($this->mirror(self::SEEN, 'h1'), 100, $this->now);
        $firstId = $this->repository->findIdBySourceId(self::SEEN);
        assertNotNull($firstId);
        assertSame('h1', $this->repository->findSyncHash(self::SEEN));

        // A changed record with the same source id updates the same row — never a duplicate.
        $this->repository->save($this->mirror(self::SEEN, 'h2'), 101, $this->now);
        assertSame($firstId, $this->repository->findIdBySourceId(self::SEEN), 'same row id after update');
        assertSame('h2', $this->repository->findSyncHash(self::SEEN));
        assertSame(1, $this->rowCount(self::SEEN), 'exactly one row for the source id');
    }

    public function testUnknownSourceIdHasNoHashOrId(): void
    {
        assertSame(null, $this->repository->findSyncHash(self::STALE));
        assertSame(null, $this->repository->findIdBySourceId(self::STALE));
    }

    public function testLongFreeTextTitleIsStoredWithoutTruncation(): void
    {
        // Real Order58 rules put long free-text in the title field; the TEXT column must keep every character.
        $longTitle = str_repeat('Folks, look at the chart, every weekend we have agents who log out at peak. ', 20);
        $this->repository->save(
            new RuleMirror(
                id: null,
                sourceId: self::SEEN,
                type: 'Rule',
                title: $longTitle,
                description: 'Body',
                ruleKeyword: null,
                createdName: 'admin2',
                sourceStoreId: null,
                active: true,
                syncHash: 'hlong',
                sourceCreatedAt: null,
                sourceUpdatedAt: null,
                snapshot: ['id' => self::SEEN],
            ),
            100,
            $this->now,
        );

        $stored = (string) $this->connection
            ->createQuery()
            ->select('title')
            ->from('{{%order58_rule_records}}')
            ->where(['source_id' => self::SEEN])
            ->scalar();

        assertSame($longTitle, $stored, 'a long title (>500 chars) is stored intact');
    }

    public function testMarkSeenKeepsARecordActiveThroughTheSweep(): void
    {
        $this->repository->save($this->mirror(self::SEEN, 'h1'), 100, $this->now);
        $this->repository->markSeen(self::SEEN, 200, $this->now);

        $deactivated = array_column($this->repository->deactivateNotSeen(200, $this->now), 'source_id');

        assertNotContains(self::SEEN, $deactivated);
        assertSame(1, $this->activeOf(self::SEEN));
    }

    public function testSweepDeactivatesUnseenRecordsAndReturnsTheirRowIds(): void
    {
        $this->repository->save($this->mirror(self::SEEN, 'h1'), 200, $this->now);   // seen this run
        $this->repository->save($this->mirror(self::STALE, 'h2'), 100, $this->now);  // seen a prior run only
        $staleRowId = $this->repository->findIdBySourceId(self::STALE);

        $deactivated = $this->repository->deactivateNotSeen(200, $this->now);
        $bySource = array_column($deactivated, 'record_id', 'source_id');

        assertNotContains(self::SEEN, array_column($deactivated, 'source_id'));
        assertContains(self::STALE, array_column($deactivated, 'source_id'));
        assertSame($staleRowId, $bySource[self::STALE] ?? null, 'the deactivated record exposes its row id');
        assertSame(1, $this->activeOf(self::SEEN));
        assertSame(0, $this->activeOf(self::STALE));
    }

    private function mirror(int $sourceId, string $hash): RuleMirror
    {
        return new RuleMirror(
            id: null,
            sourceId: $sourceId,
            type: 'Rule',
            title: 'Test rule ' . $sourceId,
            description: 'Body ' . $hash,
            ruleKeyword: null,
            createdName: 'admin2',
            sourceStoreId: null,
            active: true,
            syncHash: $hash,
            sourceCreatedAt: null,
            sourceUpdatedAt: null,
            snapshot: ['id' => $sourceId, 'title' => 'Test rule', 'description' => 'Body'],
        );
    }

    private function activeOf(int $sourceId): int
    {
        return (int) $this->connection
            ->createQuery()
            ->select('is_active')
            ->from('{{%order58_rule_records}}')
            ->where(['source_id' => $sourceId])
            ->scalar();
    }

    private function rowCount(int $sourceId): int
    {
        return (int) $this->connection
            ->createQuery()
            ->from('{{%order58_rule_records}}')
            ->where(['source_id' => $sourceId])
            ->count();
    }
}
