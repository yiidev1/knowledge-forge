<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Domain\AgentIdentity;
use App\Agent\Infrastructure\DbAgentLoginActivityRepository;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * What only real MySQL can prove: the unique key turns a second login into an update, the increment is done
 * by the database rather than read-modify-write, `first_login_at` survives, and the CHECK constraints hold
 * even if the repository were bypassed.
 */
final class AgentLoginActivityIntegrationTest extends Unit
{
    private const AGENT_A = 900000501;
    private const AGENT_B = 900000502;

    private ConnectionInterface $connection;
    private DbAgentLoginActivityRepository $repository;
    private MutableClock $clock;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->clock = new MutableClock();
        $this->repository = new DbAgentLoginActivityRepository($this->connection, $this->clock);
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testFirstLoginCreatesTheRow(): void
    {
        $this->repository->recordSuccessfulLogin($this->identity(self::AGENT_A));

        $row = $this->repository->findByAgent(self::AGENT_A);
        assertNotNull($row);
        assertSame(self::AGENT_A, $row->agentAdminId);
        assertSame(1, $row->loginCount);
        assertSame($row->firstLoginAt->format('Y-m-d H:i:s'), $row->lastLoginAt->format('Y-m-d H:i:s'));
        assertSame('agent-a', $row->username);
        assertSame('Agent A', $row->displayName);
    }

    public function testSecondLoginUpdatesOneRowAndPreservesFirstLoginAt(): void
    {
        $this->repository->recordSuccessfulLogin($this->identity(self::AGENT_A));
        $first = $this->repository->findByAgent(self::AGENT_A)?->firstLoginAt;
        assertNotNull($first);

        $this->clock->advance('+2 days');
        $this->repository->recordSuccessfulLogin($this->identity(self::AGENT_A));

        $row = $this->repository->findByAgent(self::AGENT_A);
        assertNotNull($row);
        assertSame(2, $row->loginCount);
        assertSame($first->format('Y-m-d H:i:s'), $row->firstLoginAt->format('Y-m-d H:i:s'));
        assertTrue($row->lastLoginAt > $row->firstLoginAt);
        assertSame(1, $this->countRows(self::AGENT_A), 'the unique key made it an update, not a second row');
    }

    /**
     * `login_count = login_count + 1` is evaluated by the database, so repeated writes cannot lose a count
     * the way a read-then-write would under concurrency.
     */
    public function testCountIsIncrementedByTheDatabase(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->clock->advance('+1 minute');
            $this->repository->recordSuccessfulLogin($this->identity(self::AGENT_A));
        }

        assertSame(5, $this->repository->findByAgent(self::AGENT_A)?->loginCount);
        assertSame(1, $this->countRows(self::AGENT_A));
    }

    public function testNameSnapshotIsRefreshedOnEachLogin(): void
    {
        $this->repository->recordSuccessfulLogin($this->identity(self::AGENT_A));
        $this->clock->advance('+1 day');
        $this->repository->recordSuccessfulLogin(
            new AgentIdentity(self::AGENT_A, 'agent-a-renamed', 'Agent A Renamed', null, 'active', 'agent'),
        );

        $row = $this->repository->findByAgent(self::AGENT_A);
        assertSame('agent-a-renamed', $row?->username);
        assertSame('Agent A Renamed', $row?->displayName);
        assertSame(2, $row?->loginCount);
    }

    public function testAgentsAreIsolated(): void
    {
        $this->repository->recordSuccessfulLogin($this->identity(self::AGENT_A));
        $this->clock->advance('+1 minute');
        $this->repository->recordSuccessfulLogin($this->identity(self::AGENT_B, 'agent-b', 'Agent B'));
        $this->clock->advance('+1 minute');
        $this->repository->recordSuccessfulLogin($this->identity(self::AGENT_B, 'agent-b', 'Agent B'));

        assertSame(1, $this->repository->findByAgent(self::AGENT_A)?->loginCount);
        assertSame(2, $this->repository->findByAgent(self::AGENT_B)?->loginCount);
    }

    public function testFindAllReturnsMostRecentlyActiveFirst(): void
    {
        $this->repository->recordSuccessfulLogin($this->identity(self::AGENT_A));
        $this->clock->advance('+1 hour');
        $this->repository->recordSuccessfulLogin($this->identity(self::AGENT_B, 'agent-b', 'Agent B'));

        $all = array_values(array_filter(
            $this->repository->findAll(),
            static fn($a): bool => $a->agentAdminId === self::AGENT_A || $a->agentAdminId === self::AGENT_B,
        ));

        assertCount(2, $all);
        assertSame(self::AGENT_B, $all[0]->agentAdminId, 'most recent login first');
    }

    public function testUnknownAgentHasNoActivity(): void
    {
        assertNull($this->repository->findByAgent(self::AGENT_A));
    }

    public function testDatabaseRefusesAZeroLoginCount(): void
    {
        $rejected = false;
        try {
            $this->connection->createCommand()->insert('{{%agent_login_activity}}', [
                'agent_admin_id' => self::AGENT_A,
                'username' => 'agent-a',
                'display_name' => 'Agent A',
                'first_login_at' => '2026-01-01 00:00:00',
                'last_login_at' => '2026-01-01 00:00:00',
                'login_count' => 0,
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ])->execute();
        } catch (Throwable) {
            $rejected = true;
        }

        assertTrue($rejected);
    }

    /**
     * The mirror is upstream state; this table is local usage. Recording a login must not touch it.
     */
    public function testRecordingALoginDoesNotTouchTheOrder58Mirror(): void
    {
        $before = (int) $this->connection->createQuery()->from('{{%order58_agents}}')->count('*');

        $this->repository->recordSuccessfulLogin($this->identity(self::AGENT_A));

        assertSame($before, (int) $this->connection->createQuery()->from('{{%order58_agents}}')->count('*'));
        // ...and no mirror row was invented for an agent that only exists in the activity table.
        assertSame(0, (int) $this->connection->createQuery()
            ->from('{{%order58_agents}}')
            ->where(['admin_id' => self::AGENT_A])
            ->count('*'));
    }

    // ------------------------------------------------------------------ helpers

    private function identity(int $adminId, string $username = 'agent-a', string $display = 'Agent A'): AgentIdentity
    {
        return new AgentIdentity($adminId, $username, $display, null, 'active', 'agent');
    }

    private function countRows(int $agentAdminId): int
    {
        return (int) $this->connection->createQuery()
            ->from('{{%agent_login_activity}}')
            ->where(['agent_admin_id' => $agentAdminId])
            ->count('*');
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%agent_login_activity}}', [
            'agent_admin_id' => [self::AGENT_A, self::AGENT_B],
        ]);
    }
}
