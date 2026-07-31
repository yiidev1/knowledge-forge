<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Infrastructure\DbAgentConversationRepository;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

/**
 * Proves an agent's conversations are private and store-scoped against real MySQL: an agent sees only
 * their own threads, and cannot open another agent's — or another store's — conversation by id. Sentinel
 * ids keep the shared dev database undisturbed. Skipped when no database is configured.
 */
final class AgentConversationIsolationTest extends Unit
{
    private const AGENT_A = 80001;
    private const AGENT_B = 80002;
    private const SLUG_PREFIX = '__kf_test_agentiso_';

    private ConnectionInterface $connection;
    private DbAgentConversationRepository $repository;
    private DateTimeImmutable $now;
    private int $kb1;
    private int $kb2;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->repository = new DbAgentConversationRepository($this->connection);
        $this->now = new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
        $this->kb1 = $this->insertKnowledgeBase(self::SLUG_PREFIX . '1');
        $this->kb2 = $this->insertKnowledgeBase(self::SLUG_PREFIX . '2');
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%conversations}}', ['agent_admin_id' => [self::AGENT_A, self::AGENT_B]])
            ->execute();
        // Exact slugs (the prefix contains `_`, a LIKE wildcard, so match the two sentinels precisely).
        // Deleting the sentinel knowledge bases cascades to any remaining conversations.
        $this->connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['slug' => [self::SLUG_PREFIX . '1', self::SLUG_PREFIX . '2']])
            ->execute();
    }

    private function insertKnowledgeBase(string $slug): int
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => 'Test ' . $slug,
            'slug' => $slug,
            'vector_store_status' => 'ready',
            'status' => 'active',
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function testAgentSeesOnlyTheirOwnConversationsAndCannotOpenAnother(): void
    {
        $aInKb1 = $this->repository->create($this->kb1, self::AGENT_A, 'A in store 1', $this->now);
        $bInKb1 = $this->repository->create($this->kb1, self::AGENT_B, 'B in store 1', $this->now);
        $this->repository->create($this->kb2, self::AGENT_A, 'A in store 2', $this->now);

        // Agent A's list for store 1 contains only A's own conversation.
        $listForA = $this->repository->findForAgentInKnowledgeBase($this->kb1, self::AGENT_A);
        assertCount(1, $listForA);
        assertSame($aInKb1, $listForA[0]->id);

        // A cannot open B's conversation by id (another agent).
        assertNull($this->repository->findForAgent($bInKb1, $this->kb1, self::AGENT_A));
        // A cannot open their own store-1 conversation under store 2 (another store).
        assertNull($this->repository->findForAgent($aInKb1, $this->kb2, self::AGENT_A));
        // A can open their own conversation in the right store.
        assertNotNull($this->repository->findForAgent($aInKb1, $this->kb1, self::AGENT_A));
    }
}
