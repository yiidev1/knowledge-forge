<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Infrastructure\DbAgentConversationRepository;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Infrastructure\DbConversationRepository;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

/**
 * Agent threads are private per (store, agent). Admin threads use participant_type=admin.
 */
final class AgentConversationIsolationTest extends Unit
{
    private const AGENT_A = 80001;
    private const AGENT_B = 80002;
    private const SLUG_PREFIX = '__kf_test_agentiso_';

    private ConnectionInterface $connection;
    private DbAgentConversationRepository $repository;
    private DbConversationRepository $conversations;
    private DateTimeImmutable $now;
    private int $kb1;
    private int $kb2;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->conversations = new DbConversationRepository($this->connection);
        $this->repository = new DbAgentConversationRepository($this->conversations);
        $this->now = new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
        $this->kb1 = $this->insertKnowledgeBase(self::SLUG_PREFIX . '1');
        $this->kb2 = $this->insertKnowledgeBase(self::SLUG_PREFIX . '2');
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testIsolationMatrix(): void
    {
        $adminKb1 = $this->conversations->createThread(
            $this->kb1,
            ChatParticipant::admin(1),
            'Admin store 1',
            $this->now,
        );
        $aInKb1 = $this->repository->create($this->kb1, self::AGENT_A, 'A in store 1', $this->now);
        $bInKb1 = $this->repository->create($this->kb1, self::AGENT_B, 'B in store 1', $this->now);
        $aInKb2 = $this->repository->create($this->kb2, self::AGENT_A, 'A in store 2', $this->now);

        assertSame($aInKb1, $this->repository->findThread($this->kb1, self::AGENT_A)?->id);
        assertNull($this->repository->findForAgent($bInKb1, $this->kb1, self::AGENT_A));
        assertNull($this->repository->findForAgent($aInKb1, $this->kb2, self::AGENT_A));
        assertNull($this->repository->findForAgent($adminKb1, $this->kb1, self::AGENT_A));
        assertNotNull($this->repository->findForAgent($aInKb1, $this->kb1, self::AGENT_A));
        assertSame($aInKb2, $this->repository->findThread($this->kb2, self::AGENT_A)?->id);
        assertNull($this->conversations->findOwnedThreadById(
            $aInKb1,
            $this->kb1,
            ChatParticipant::admin(1),
        ));
        assertNotNull($this->conversations->findOwnedThreadById(
            $adminKb1,
            $this->kb1,
            ChatParticipant::admin(1),
        ));
        assertNull($this->conversations->findOwnedThreadById(
            $adminKb1,
            $this->kb1,
            ChatParticipant::admin(2),
        ));
    }

    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%conversations}}', [
                'participant_type' => 'agent',
                'participant_id' => [self::AGENT_A, self::AGENT_B],
            ])
            ->execute();
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
}
