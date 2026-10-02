<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Domain\StoreMirror;
use App\Order58\Infrastructure\DbOrder58StoreRepository;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The host column against real MySQL: it is written on insert, refreshed only when it moved, and never
 * cleared by a response that simply did not mention it.
 *
 * Sentinel source ids in the 9000000xx range keep the shared dev database undisturbed, the same
 * convention {@see Order58StoreSweepTest} uses. Skipped when no database is configured **or when the
 * `host` column does not exist yet** — the migration is deliberately not applied as part of this change,
 * and a test that failed for that reason would be reporting a deployment step as a defect.
 */
final class StoreHostPersistenceTest extends Unit
{
    private const NEW_STORE = 900000101;
    private const EXISTING = 900000102;
    private const OTHER = 900000103;

    private const HOST = '0000000004.17ip.com';
    private const MOVED = 'moved.order58.com';

    private ConnectionInterface $connection;
    private DbOrder58StoreRepository $repository;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();

        if ($this->connection->getTableSchema('order58_stores', true)?->getColumn('host') === null) {
            $this->markTestSkipped('order58_stores.host does not exist yet — run the migration first.');
        }

        $this->repository = new DbOrder58StoreRepository($this->connection);
        $this->now = new DateTimeImmutable('2026-10-02 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ insert

    public function testANewStoreIsSavedWithItsHost(): void
    {
        $this->repository->save($this->mirror(self::NEW_STORE, self::HOST), 1, $this->now);

        assertSame(self::HOST, $this->hostOf(self::NEW_STORE));
    }

    public function testANewStoreWithNoUsableHostStoresNull(): void
    {
        $this->repository->save($this->mirror(self::NEW_STORE, null), 1, $this->now);

        assertNull($this->hostOf(self::NEW_STORE));
    }

    // ------------------------------------------------------------------ update

    public function testAChangedHostIsWritten(): void
    {
        $this->repository->save($this->mirror(self::EXISTING, self::HOST), 1, $this->now);

        assertTrue($this->repository->updateHostIfChanged(self::EXISTING, self::MOVED, $this->now));
        assertSame(self::MOVED, $this->hostOf(self::EXISTING));
    }

    /** An unchanged host must not produce a write, or every sync would touch every row for nothing. */
    public function testAnUnchangedHostWritesNothing(): void
    {
        $this->repository->save($this->mirror(self::EXISTING, self::HOST), 1, $this->now);

        assertFalse($this->repository->updateHostIfChanged(self::EXISTING, self::HOST, $this->now));
        assertSame(self::HOST, $this->hostOf(self::EXISTING));
    }

    /**
     * A row that has none yet acquires one.
     *
     * `host <> ?` is NULL rather than true for a NULL column, so without the explicit NULL arm in the
     * predicate a backfilled-as-NULL store could never take a host. This is that arm.
     */
    public function testAStoreWithNoHostAcquiresOne(): void
    {
        $this->repository->save($this->mirror(self::EXISTING, null), 1, $this->now);
        assertNull($this->hostOf(self::EXISTING));

        assertTrue($this->repository->updateHostIfChanged(self::EXISTING, self::HOST, $this->now));
        assertSame(self::HOST, $this->hostOf(self::EXISTING));
    }

    /** **The rule.** Null means "the source said nothing usable", so the stored value survives. */
    public function testANullHostNeverClearsAStoredOne(): void
    {
        $this->repository->save($this->mirror(self::EXISTING, self::HOST), 1, $this->now);

        assertFalse($this->repository->updateHostIfChanged(self::EXISTING, null, $this->now));
        assertSame(self::HOST, $this->hostOf(self::EXISTING));
    }

    /**
     * A re-save must not blank the host either.
     *
     * `save()` carries the host on INSERT but leaves it out of the UPDATE half precisely so that an
     * omitted field cannot overwrite a good value on the second sync. This is that guarantee.
     */
    public function testResavingWithoutAHostLeavesTheStoredOneAlone(): void
    {
        $this->repository->save($this->mirror(self::EXISTING, self::HOST), 1, $this->now);
        $this->repository->save($this->mirror(self::EXISTING, null), 2, $this->now);

        assertSame(self::HOST, $this->hostOf(self::EXISTING));
    }

    // ------------------------------------------------------------------ isolation

    public function testOneStoresHostNeverReachesAnother(): void
    {
        $this->repository->save($this->mirror(self::EXISTING, self::HOST), 1, $this->now);
        $this->repository->save($this->mirror(self::OTHER, 'other.order58.com'), 1, $this->now);

        $this->repository->updateHostIfChanged(self::EXISTING, self::MOVED, $this->now);

        assertSame(self::MOVED, $this->hostOf(self::EXISTING));
        assertSame('other.order58.com', $this->hostOf(self::OTHER));
    }

    /** A host write touches the host and the timestamp, and nothing else on the row. */
    public function testAHostWriteLeavesEveryOtherColumnAlone(): void
    {
        $this->repository->save($this->mirror(self::EXISTING, self::HOST), 7, $this->now);
        $before = $this->rowOf(self::EXISTING);

        $this->repository->updateHostIfChanged(self::EXISTING, self::MOVED, $this->now);
        $after = $this->rowOf(self::EXISTING);

        foreach (['name', 'company', 'active', 'sync_hash', 'snapshot_json', 'last_seen_sync_run_id', 'synced_at', 'created_at'] as $column) {
            assertSame($before[$column], $after[$column], $column . ' must not change when only the host does.');
        }
    }

    /** Hydration reads the column back, so a mirror round-trips. */
    public function testTheHostIsReadBackOnTheMirror(): void
    {
        $this->repository->save($this->mirror(self::EXISTING, self::HOST), 1, $this->now);

        assertSame(self::HOST, $this->repository->findBySourceId(self::EXISTING)?->host);
    }

    // ------------------------------------------------------------------ helpers

    private function mirror(int $sourceId, ?string $host): StoreMirror
    {
        return new StoreMirror(
            id: null,
            sourceId: $sourceId,
            name: 'Test ' . $sourceId,
            company: '0004',
            active: true,
            syncHash: 'h' . $sourceId,
            sourceUpdatedAt: null,
            snapshot: ['id' => $sourceId, 'name' => 'Test', 'active' => true, 'fields' => []],
            host: $host,
        );
    }

    private function hostOf(int $sourceId): ?string
    {
        $value = $this->connection->createQuery()
            ->select('host')->from('{{%order58_stores}}')->where(['source_id' => $sourceId])->scalar();

        return $value === null || $value === false ? null : (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOf(int $sourceId): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->connection->createQuery()
            ->from('{{%order58_stores}}')->where(['source_id' => $sourceId])->one();

        return $row;
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup(
            $this->connection,
            '{{%order58_stores}}',
            ['source_id' => [self::NEW_STORE, self::EXISTING, self::OTHER]],
        );
    }
}
