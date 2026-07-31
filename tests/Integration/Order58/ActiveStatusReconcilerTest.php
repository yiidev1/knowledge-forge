<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseSourceRepository;
use App\Order58\Application\ActiveStatusReconciler;
use App\Order58\Infrastructure\DbOrder58StoreRepository;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function json_encode;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertSame;

/**
 * The active-status reconciliation against real MySQL: it repairs a store mirror whose `active` column was
 * written wrong (from the still-correct snapshot), propagates to the mapped knowledge base's `source_active`
 * without touching `agent_enabled`, leaves the `_sync_hash` alone (so it is independent of change
 * detection), and never overwrites a valid status when the snapshot flag is unusable. Sentinel source ids
 * keep the shared dev database undisturbed.
 */
final class ActiveStatusReconcilerTest extends Unit
{
    private const STALE_ACTIVE = 900000101;   // column 0, snapshot true → should become 1
    private const INVALID_SNAPSHOT = 900000102; // column 1, snapshot garbage → must stay 1
    private const STALE_INACTIVE = 900000103;  // column 1, snapshot false → should become 0

    private ConnectionInterface $connection;
    private DbOrder58StoreRepository $stores;
    private DbKnowledgeBaseSourceRepository $knowledgeBases;
    private MutableClock $clock;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->stores = new DbOrder58StoreRepository($this->connection);
        $this->knowledgeBases = new DbKnowledgeBaseSourceRepository($this->connection);
        $this->clock = new MutableClock('2026-02-01 00:00:00');
        $this->now = new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $ids = [self::STALE_ACTIVE, self::INVALID_SNAPSHOT, self::STALE_INACTIVE];
        IntegrationDb::cleanup($this->connection, '{{%order58_stores}}', ['source_id' => $ids]);
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['source_store_id' => $ids]);
    }

    private function reconciler(): ActiveStatusReconciler
    {
        return new ActiveStatusReconciler($this->stores, $this->knowledgeBases, $this->clock);
    }

    /**
     * @param bool|string $snapshotActive the value placed in snapshot_json.active
     */
    private function seedStore(int $sourceId, int $columnActive, bool|string $snapshotActive, string $hash): void
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => 'Sentinel ' . $sourceId,
            'active' => $columnActive,
            'snapshot_json' => (string) json_encode(['id' => $sourceId, 'name' => 'Sentinel', 'active' => $snapshotActive, 'fields' => []]),
            'sync_hash' => $hash,
            'synced_at' => $ts,
            'last_seen_sync_run_id' => 1,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
    }

    private function seedKnowledgeBase(int $sourceId, bool $sourceActive): int
    {
        return $this->knowledgeBases->createForSource(
            'Sentinel ' . $sourceId,
            'sentinel-' . $sourceId,
            'order58',
            $sourceId,
            'Sentinel ' . $sourceId,
            $sourceActive,
            $this->now,
        );
    }

    private function storeActive(int $sourceId): int
    {
        return (int) $this->connection->createQuery()->select('active')->from('{{%order58_stores}}')->where(['source_id' => $sourceId])->scalar();
    }

    /**
     * @return array{source_active:int, agent_enabled:int}
     */
    private function kbState(int $kbId): array
    {
        /** @var array<string,mixed> $row */
        $row = $this->connection->createQuery()->select(['source_active', 'agent_enabled'])->from('{{%knowledge_bases}}')->where(['id' => $kbId])->one();

        return ['source_active' => (int) $row['source_active'], 'agent_enabled' => (int) $row['agent_enabled']];
    }

    public function testStaleActiveIsRepairedFromSnapshotPreservingAgentEnabledAndHash(): void
    {
        $this->seedStore(self::STALE_ACTIVE, 0, true, 'hStaleActive');
        $kbId = $this->seedKnowledgeBase(self::STALE_ACTIVE, false);

        $report = $this->reconciler()->reconcile();

        // Store column and KB source_active both corrected to active.
        assertSame(1, $this->storeActive(self::STALE_ACTIVE), 'stale active column repaired from snapshot');
        $kb = $this->kbState($kbId);
        assertSame(1, $kb['source_active'], 'KB source_active propagated');
        assertSame(1, $kb['agent_enabled'], 'agent_enabled must be preserved (local override, untouched)');

        // The reconciliation ignores _sync_hash entirely, so change detection cannot suppress it.
        assertSame('hStaleActive', $this->stores->findSyncHash(self::STALE_ACTIVE), 'sync hash left untouched');

        assertGreaterThanOrEqual(1, $report->storesCorrected);
        assertGreaterThanOrEqual(1, $report->knowledgeBasesCorrected);
    }

    public function testStaleInactiveIsRepairedToInactive(): void
    {
        $this->seedStore(self::STALE_INACTIVE, 1, false, 'hStaleInactive');
        $kbId = $this->seedKnowledgeBase(self::STALE_INACTIVE, true);

        $this->reconciler()->reconcile();

        assertSame(0, $this->storeActive(self::STALE_INACTIVE));
        assertSame(0, $this->kbState($kbId)['source_active']);
    }

    public function testInvalidSnapshotFlagNeverOverwritesAValidStatus(): void
    {
        $this->seedStore(self::INVALID_SNAPSHOT, 1, 'garbage', 'hInvalid');
        $kbId = $this->seedKnowledgeBase(self::INVALID_SNAPSHOT, true);

        $report = $this->reconciler()->reconcile();

        assertSame(1, $this->storeActive(self::INVALID_SNAPSHOT), 'an unusable snapshot flag must not flip active to 0');
        assertSame(1, $this->kbState($kbId)['source_active']);
        assertGreaterThanOrEqual(1, $report->skippedInvalid);
    }
}
