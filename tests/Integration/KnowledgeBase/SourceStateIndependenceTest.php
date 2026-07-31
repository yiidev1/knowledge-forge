<?php

declare(strict_types=1);

namespace App\Tests\Integration\KnowledgeBase;

use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseSourceRepository;
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
 * `source_active` (mirrors Order58) and `agent_enabled` (the local administrator override) are independent
 * axes on a source-backed knowledge base. This proves, against real MySQL, that a store-state refresh never
 * disturbs an explicit agent-access choice, and that the reconciliation write is confined to `source_active`.
 */
final class SourceStateIndependenceTest extends Unit
{
    private const STORE = 900000401;

    private ConnectionInterface $connection;
    private DbKnowledgeBaseSourceRepository $repository;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->repository = new DbKnowledgeBaseSourceRepository($this->connection);
        $this->now = new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['source_store_id' => self::STORE]);
    }

    private function createKb(bool $sourceActive): int
    {
        return $this->repository->createForSource('KB', 'kb-src-state-' . self::STORE, 'order58', self::STORE, 'KB', $sourceActive, $this->now);
    }

    /**
     * @return array{source_active:int, agent_enabled:int}
     */
    private function state(int $id): array
    {
        /** @var array<string,mixed> $row */
        $row = $this->connection->createQuery()->select(['source_active', 'agent_enabled'])->from('{{%knowledge_bases}}')->where(['id' => $id])->one();

        return ['source_active' => (int) $row['source_active'], 'agent_enabled' => (int) $row['agent_enabled']];
    }

    public function testNewSourceKnowledgeBaseDefaultsToAgentEnabled(): void
    {
        // A freshly synced store — even an inactive one — is agent-enabled by default; visibility is gated
        // by source_active, not by auto-disabling the local override.
        $id = $this->createKb(sourceActive: false);

        assertSame(['source_active' => 0, 'agent_enabled' => 1], $this->state($id));
    }

    public function testUpdateSourceStateNeverOverwritesAnExplicitAgentDisable(): void
    {
        $id = $this->createKb(sourceActive: true);
        $this->repository->setAgentEnabled($id, false, $this->now);

        // A later Sync Stores refresh flips source status but must leave the admin's disable in place.
        $this->repository->updateSourceState($id, 'KB renamed', 'KB renamed', false, $this->now);
        assertSame(['source_active' => 0, 'agent_enabled' => 0], $this->state($id));

        $this->repository->updateSourceState($id, 'KB', 'KB', true, $this->now);
        assertSame(['source_active' => 1, 'agent_enabled' => 0], $this->state($id), 'agent_enabled disable survives a re-sync');
    }

    public function testFindOrder58StoreIdResolvesTheSourceStoreForTheManageKbSyncButton(): void
    {
        $kbId = $this->createKb(sourceActive: true);

        assertSame(self::STORE, $this->repository->findOrder58StoreId($kbId));
        // A non-existent (or non-Order58) knowledge base yields null, so the button is only offered for
        // Order58-backed bases.
        assertNull($this->repository->findOrder58StoreId($kbId + 10_000_000));
    }

    public function testReconcileSourceActiveReportsOnlyRealChangesAndLeavesAgentEnabled(): void
    {
        $id = $this->createKb(sourceActive: false);
        $this->repository->setAgentEnabled($id, false, $this->now);

        assertTrue($this->repository->reconcileSourceActive($id, true, $this->now), 'a real change is reported');
        assertFalse($this->repository->reconcileSourceActive($id, true, $this->now), 'an already-correct row is not rewritten');

        assertSame(['source_active' => 1, 'agent_enabled' => 0], $this->state($id));
    }
}
