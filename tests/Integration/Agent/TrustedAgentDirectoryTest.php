<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Infrastructure\DbTrustedAgentDirectory;
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
 * The fallback identity resolver against real MySQL.
 *
 * The cases that matter are the ones the live mirror actually contains: `username` has **no** unique index,
 * and real collisions exist there (`alyssa` twice, `angelmae` twice, `shiela` as both an agent and an
 * employee). The `user_type`/`status` predicate resolves every one of them today — but by data, not by
 * constraint — so ambiguity has to be refused rather than assumed away. Sentinel `admin_id`s keep the shared
 * dev database undisturbed.
 */
final class TrustedAgentDirectoryTest extends Unit
{
    private const BASE_ID = 900000601;
    private const USERNAME = '__kf_test_trusted_agent__';
    private const OTHER_USERNAME = '__kf_test_trusted_other__';

    private ConnectionInterface $connection;
    private DbTrustedAgentDirectory $directory;
    private DateTimeImmutable $now;
    private DateTimeImmutable $cutoff;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->directory = new DbTrustedAgentDirectory($this->connection);
        $this->now = new DateTimeImmutable('2026-05-20 12:00:00', new DateTimeZone('UTC'));
        // The production window: 72 hours before "now".
        $this->cutoff = $this->now->modify('-72 hours');
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    // ---------------------------------------------------------------- the happy path

    public function testResolvesASingleFreshActiveAgent(): void
    {
        $this->insertAgent(0, self::USERNAME, 'agent', 'active', $this->now->modify('-1 hour'));

        $result = $this->directory->findActiveAgentByUsername(self::USERNAME, $this->cutoff);

        assertNotNull($result->agent);
        assertSame(self::BASE_ID, $result->agent->adminId);
        assertSame(self::USERNAME, $result->agent->username);
        assertSame('Anna Smith', $result->agent->displayName);
        assertSame('anna@test.com', $result->agent->email);
        assertSame('agent', $result->agent->userType);
        assertSame('active', $result->agent->status);
        assertSame('trusted_agent_resolved', $result->reason);
    }

    public function testDisplayNameFallsBackToTheUsername(): void
    {
        $this->insertAgent(0, self::USERNAME, 'agent', 'active', $this->now, firstName: null, lastName: null);

        $result = $this->directory->findActiveAgentByUsername(self::USERNAME, $this->cutoff);

        assertSame(self::USERNAME, $result->agent?->displayName);
    }

    // ---------------------------------------------------------------- authorization

    /**
     * The judy case: a real, active account whose password the validate API confirms, but who is not an
     * agent. Admitting them would be a privilege escalation into the agent realm.
     *
     * @dataProvider nonAgentTypes
     */
    public function testANonAgentIsNeverResolved(string $userType): void
    {
        $this->insertAgent(0, self::USERNAME, $userType, 'active', $this->now);

        $result = $this->directory->findActiveAgentByUsername(self::USERNAME, $this->cutoff);

        assertNull($result->agent);
        assertSame('no_active_agent', $result->reason);
    }

    public function nonAgentTypes(): array
    {
        return [['admin'], ['merchant'], ['operation'], ['trainee'], ['employee']];
    }

    /** @dataProvider inactiveStatuses */
    public function testAnInactiveAgentIsNeverResolved(string $status): void
    {
        $this->insertAgent(0, self::USERNAME, 'agent', $status, $this->now);

        $result = $this->directory->findActiveAgentByUsername(self::USERNAME, $this->cutoff);

        assertNull($result->agent);
        assertSame('no_active_agent', $result->reason);
    }

    public function inactiveStatuses(): array
    {
        return [['disable'], ['inactive']];
    }

    public function testAnAbsentUsernameIsNotFound(): void
    {
        $result = $this->directory->findActiveAgentByUsername(self::USERNAME, $this->cutoff);

        assertNull($result->agent);
        assertSame('no_active_agent', $result->reason);
    }

    public function testAnEmptyUsernameIsNotFoundWithoutQuerying(): void
    {
        assertSame('no_active_agent', $this->directory->findActiveAgentByUsername('   ', $this->cutoff)->reason);
    }

    // ---------------------------------------------------------------- ambiguity

    public function testTwoActiveAgentsSharingAUsernameAreRefusedNotGuessed(): void
    {
        $this->insertAgent(0, self::USERNAME, 'agent', 'active', $this->now);
        $this->insertAgent(1, self::USERNAME, 'agent', 'active', $this->now);

        $result = $this->directory->findActiveAgentByUsername(self::USERNAME, $this->cutoff);

        assertNull($result->agent);
        assertSame('ambiguous_username', $result->reason);
    }

    /**
     * The live shape of every real collision: one active agent alongside a disabled or non-agent twin. The
     * predicate must narrow to the single admissible row rather than reporting ambiguity.
     */
    public function testACollisionResolvedByTypeAndStatusIsNotAmbiguous(): void
    {
        $this->insertAgent(0, self::USERNAME, 'agent', 'active', $this->now);
        $this->insertAgent(1, self::USERNAME, 'agent', 'disable', $this->now);
        $this->insertAgent(2, self::USERNAME, 'employee', 'active', $this->now);

        $result = $this->directory->findActiveAgentByUsername(self::USERNAME, $this->cutoff);

        assertSame(self::BASE_ID, $result->agent?->adminId);
    }

    // ---------------------------------------------------------------- freshness

    public function testARowOlderThanTheWindowIsRefused(): void
    {
        // 73 hours old against a 72-hour window.
        $this->insertAgent(0, self::USERNAME, 'agent', 'active', $this->now->modify('-73 hours'));

        $result = $this->directory->findActiveAgentByUsername(self::USERNAME, $this->cutoff);

        assertNull($result->agent);
        assertSame('mirror_row_stale', $result->reason);
    }

    public function testARowInsideTheWindowIsAccepted(): void
    {
        $this->insertAgent(0, self::USERNAME, 'agent', 'active', $this->now->modify('-71 hours'));

        assertNotNull($this->directory->findActiveAgentByUsername(self::USERNAME, $this->cutoff)->agent);
    }

    public function testTheLookupIsScopedToTheUsernameAsked(): void
    {
        $this->insertAgent(0, self::USERNAME, 'agent', 'active', $this->now);

        assertNull($this->directory->findActiveAgentByUsername(self::OTHER_USERNAME, $this->cutoff)->agent);
    }

    // ---------------------------------------------------------------- fixture

    private function insertAgent(
        int $offset,
        string $username,
        string $userType,
        string $status,
        DateTimeImmutable $syncedAt,
        ?string $firstName = 'Anna',
        ?string $lastName = 'Smith',
    ): void {
        $ts = DbDateTime::format($this->now);

        $this->connection->createCommand()->insert('{{%order58_agents}}', [
            'admin_id' => self::BASE_ID + $offset,
            'username' => $username,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email_address' => 'anna@test.com',
            'status' => $status,
            'user_type' => $userType,
            // Present in the row and deliberately never read by the resolver.
            'account_id' => 21,
            'sync_hash' => str_repeat('a', 64),
            'synced_at' => DbDateTime::format($syncedAt),
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();
        IntegrationDb::cleanup($connection, '{{%order58_agents}}', [
            'admin_id' => [self::BASE_ID, self::BASE_ID + 1, self::BASE_ID + 2],
        ]);
    }
}
