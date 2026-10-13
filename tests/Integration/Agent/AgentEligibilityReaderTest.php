<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Domain\Order58Role;
use App\Agent\Infrastructure\DbAgentEligibilityReader;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

/**
 * Re-reading one signed-in user's entitlement, which the Order Testing gate does on **every** request.
 *
 * This is what makes revocation land on the next click instead of at session expiry, so the tests that
 * matter most are the ones where a session already exists and the mirror has since changed underneath
 * it. Sentinel `admin_id`s keep the shared dev database undisturbed.
 */
final class AgentEligibilityReaderTest extends Unit
{
    private const BASE_ID = 900000701;
    private const USERNAME = '__kf_test_eligibility__';

    private ConnectionInterface $connection;
    private DbAgentEligibilityReader $reader;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->reader = new DbAgentEligibilityReader($this->connection, new NullLogger());
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testAnActiveOperationUserIsEntitled(): void
    {
        $this->insert('operation', 'active');

        assertSame(Order58Role::Operation, $this->reader->currentRoleFor(self::BASE_ID));
    }

    public function testAnActiveAgentIsEntitledAsAnAgent(): void
    {
        $this->insert('agent', 'active');

        assertSame(Order58Role::Agent, $this->reader->currentRoleFor(self::BASE_ID));
    }

    /**
     * The revocation cases. Each one is a user who signed in legitimately and whose mirror row then
     * changed — the gate must refuse them on the very next request.
     *
     * @dataProvider revocations
     */
    public function testARevokedUserLosesEntitlement(string $userType, string $status): void
    {
        $this->insert($userType, $status);

        assertNull($this->reader->currentRoleFor(self::BASE_ID));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function revocations(): array
    {
        return [
            'operation disabled' => ['operation', 'disable'],
            'operation deactivated' => ['operation', 'inactive'],
            'agent disabled' => ['agent', 'disable'],
            // The role change case: still active, but no longer something this login admits.
            'demoted to merchant' => ['merchant', 'active'],
            'demoted to trainee' => ['trainee', 'active'],
            'promoted to admin' => ['admin', 'active'],
            // Real mixed-case value; a case-insensitive comparison would wrongly admit it.
            'pre-training' => ['PreTraining', 'active'],
        ];
    }

    /** A session naming a row that no longer exists is refused, not treated as unknown-but-fine. */
    public function testAMissingMirrorRowIsRefused(): void
    {
        assertNull($this->reader->currentRoleFor(self::BASE_ID));
    }

    /** @dataProvider nonsenseIds */
    public function testANonPositiveIdIsRefusedWithoutQuerying(int $id): void
    {
        assertNull($this->reader->currentRoleFor($id));
    }

    /** @return list<array{0: int}> */
    public static function nonsenseIds(): array
    {
        return [[0], [-1]];
    }

    /**
     * Keyed on `admin_id`, never on the username.
     *
     * The mirror duplicates 136 usernames, so a username lookup here would reintroduce ambiguity on
     * every request. `admin_id` is UNIQUE, so a second row with the same username changes nothing.
     */
    public function testADuplicateUsernameDoesNotDisturbTheLookup(): void
    {
        $this->insert('operation', 'active');
        $this->insert('merchant', 'active', self::BASE_ID + 1);

        assertSame(Order58Role::Operation, $this->reader->currentRoleFor(self::BASE_ID));
        assertNull($this->reader->currentRoleFor(self::BASE_ID + 1));
    }

    private function insert(string $userType, string $status, ?int $adminId = null): void
    {
        $this->connection->createCommand()->insert('{{%order58_agents}}', [
            'admin_id' => $adminId ?? self::BASE_ID,
            'username' => self::USERNAME,
            'status' => $status,
            'user_type' => $userType,
            'sync_hash' => str_repeat('a', 64),
            'synced_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%order58_agents}}', ['username' => self::USERNAME]);
    }
}
