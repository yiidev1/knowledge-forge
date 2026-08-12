<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure;

use App\Agent\Domain\AgentIdentity;
use App\Agent\Domain\AgentLoginActivity;
use App\Agent\Domain\AgentLoginActivityRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\QueryInterface;

use function is_array;

/**
 * MySQL-backed agent sign-in activity.
 *
 * Owns its clock, like {@see \App\Auth\Infrastructure\DbLoginThrottle} — the other persistence collaborator
 * of a login service — so the login service does not have to carry one just to stamp this row.
 */
final readonly class DbAgentLoginActivityRepository implements AgentLoginActivityRepositoryInterface
{
    private const TABLE = '{{%agent_login_activity}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function recordSuccessfulLogin(AgentIdentity $identity): void
    {
        $now = DbDateTime::format($this->clock->now());

        // One statement, so two concurrent logins converge on one row instead of racing a read-modify-write:
        // the unique key decides insert-vs-update, and the increment is computed by the database.
        //
        // `first_login_at` is deliberately absent from the UPDATE list — it is written once, on the very
        // first sign-in, and every later login leaves it alone. The name fields ARE refreshed, so a renamed
        // agent reads correctly without joining the Order58 mirror.
        $this->connection->createCommand(
            'INSERT INTO ' . self::TABLE
            . ' ([[agent_admin_id]], [[username]], [[display_name]],'
            . ' [[first_login_at]], [[last_login_at]], [[login_count]], [[created_at]], [[updated_at]])'
            . ' VALUES (:adminId, :username, :displayName, :now, :now, 1, :now, :now)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' [[last_login_at]] = VALUES([[last_login_at]]),'
            . ' [[login_count]] = [[login_count]] + 1,'
            . ' [[username]] = VALUES([[username]]),'
            . ' [[display_name]] = VALUES([[display_name]]),'
            . ' [[updated_at]] = VALUES([[updated_at]])',
            [
                ':adminId' => $identity->adminId,
                ':username' => $identity->username,
                ':displayName' => $identity->displayName,
                ':now' => $now,
            ],
        )->execute();
    }

    public function findByAgent(int $agentAdminId): ?AgentLoginActivity
    {
        $row = $this->query()
            ->where(['agent_admin_id' => $agentAdminId])
            ->limit(1)
            ->one();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findAll(): array
    {
        $rows = $this->query()
            ->orderBy(['last_login_at' => SORT_DESC, 'agent_admin_id' => SORT_ASC])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $this->hydrate($row);
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): AgentLoginActivity
    {
        return new AgentLoginActivity(
            agentAdminId: (int) $row['agent_admin_id'],
            username: (string) $row['username'],
            displayName: (string) $row['display_name'],
            firstLoginAt: DbDateTime::parse((string) $row['first_login_at']),
            lastLoginAt: DbDateTime::parse((string) $row['last_login_at']),
            loginCount: (int) $row['login_count'],
        );
    }

    private function query(): QueryInterface
    {
        return $this->connection->createQuery()->from(self::TABLE);
    }
}
