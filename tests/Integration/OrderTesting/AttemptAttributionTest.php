<?php

declare(strict_types=1);

namespace App\Tests\Integration\OrderTesting;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Infrastructure\DbTestAttemptRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function bin2hex;
use function gmdate;
use function random_bytes;
use function str_repeat;

/**
 * Who an attempt says ran it, when two realms number a person identically.
 *
 * ## The collision is real, not contrived
 *
 * `order_testing_attempts` carries one polymorphic id column, `initiated_by_id`, beside the realm.
 * `admin_users.id` and Order58's `admin_id` are separate sequences, so the same number names a
 * different person in each — and operation users and agents share the *same* Order58 sequence as each
 * other. Three realms, two id spaces, one column.
 *
 * A join on the id alone therefore renders whichever row happens to exist. That is not hypothetical:
 * it was live behaviour until the joins in {@see DbTestAttemptRepository} were qualified on the realm,
 * and an operation user's demo order displayed an administrator's username.
 *
 * This suite seeds exactly that collision — one `admin_users` row and one `order58_agents` row holding
 * the **same number** — and asserts each realm's attempt reports its own name and never the other's.
 *
 * Every row it writes is removed in `_after`, by its own markers. The store id is far outside the
 * mirrored range and no real attempt is read or touched.
 */
final class AttemptAttributionTest extends Unit
{
    private const STORE = 987654931;

    private ConnectionInterface $connection;
    private DbTestAttemptRepository $attempts;

    /** The number both realms will use. */
    private int $sharedId;

    private string $adminUsername;
    private string $operationUsername;
    private string $agentUsername;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->attempts = new DbTestAttemptRepository($this->connection);

        $suffix = bin2hex(random_bytes(4));
        $this->adminUsername = 'kf-attr-admin-' . $suffix;
        $this->operationUsername = 'kf-attr-operation-' . $suffix;
        $this->agentUsername = 'kf-attr-agent-' . $suffix;

        // The administrator first, because its id is what the other two realms will deliberately reuse.
        $this->sharedId = (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create($this->adminUsername, 'x');

        // An operation user carrying the SAME number in Order58's own sequence.
        $this->mirrorUser($this->sharedId, $this->operationUsername, 'operation');

        // And an agent. Agents and operation users share one sequence, so this one needs its own
        // number — the realm column is what tells those two apart, not the id.
        $this->mirrorUser($this->sharedId + 1, $this->agentUsername, 'agent');
    }

    protected function _after(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%order_testing_attempts}}', ['store_source_id' => self::STORE]);

        foreach ([$this->operationUsername, $this->agentUsername] as $username) {
            IntegrationDb::cleanup($this->connection, '{{%order58_agents}}', ['username' => $username]);
        }

        IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => $this->adminUsername]);
    }

    /**
     * **The regression this exists for.**
     *
     * An operation user whose Order58 `admin_id` equals an `admin_users.id` must not be rendered as
     * that administrator.
     */
    public function testAnOperationAttemptDoesNotBorrowTheAdministratorsName(): void
    {
        $this->startAs(InitiatorType::Admin, $this->sharedId, 16900001);
        $operation = $this->startAs(InitiatorType::Operation, $this->sharedId, 16900002);

        self::assertSame(
            $this->operationUsername,
            $operation,
            'The operation attempt must report the Order58 user of that number, not the administrator.',
        );
    }

    public function testAnAdministratorAttemptReportsTheAdministrator(): void
    {
        self::assertSame(
            $this->adminUsername,
            $this->startAs(InitiatorType::Admin, $this->sharedId, 16900003),
        );
    }

    public function testAnAgentAttemptReportsTheAgent(): void
    {
        self::assertSame(
            $this->agentUsername,
            $this->startAs(InitiatorType::Agent, $this->sharedId + 1, 16900004),
        );
    }

    /** All three at once, each on its own order, each reporting itself. */
    public function testThreeRealmsSharingOneIdSpaceEachReportThemselves(): void
    {
        $names = [
            'admin' => $this->startAs(InitiatorType::Admin, $this->sharedId, 16900005),
            'operation' => $this->startAs(InitiatorType::Operation, $this->sharedId, 16900006),
            'agent' => $this->startAs(InitiatorType::Agent, $this->sharedId + 1, 16900007),
        ];

        self::assertSame(
            [
                'admin' => $this->adminUsername,
                'operation' => $this->operationUsername,
                'agent' => $this->agentUsername,
            ],
            $names,
        );
    }

    /**
     * An attempt whose account is gone still reports itself.
     *
     * The joins are LEFT for this reason: a removed user must cost a name, never a row.
     */
    public function testAnAttemptWithNoSurvivingAccountStillReads(): void
    {
        // A number neither realm has.
        $orphan = $this->startAs(InitiatorType::Admin, 2147480000, 16900008);

        self::assertNull($orphan);
    }

    /** The realm an attempt was started in is what comes back, not a guess from the id. */
    public function testTheStoredRealmSurvivesTheRoundTrip(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->attempts->start(
            self::STORE,
            16900009,
            InitiatorType::Agent,
            $this->sharedId + 1,
            $now,
            $now->modify('+1 hour'),
        );

        $attempt = $this->attempts->openFor(self::STORE, 16900009, $now);

        self::assertNotNull($attempt);
        self::assertSame(InitiatorType::Agent, $attempt->initiatedByType);
        self::assertSame($this->sharedId + 1, $attempt->initiatedById);
    }

    // ------------------------------------------------------------------ helpers

    /** Starts one attempt and returns the name the repository reports for it. */
    private function startAs(InitiatorType $type, int $id, int $sourceOrderId): ?string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->attempts->start(self::STORE, $sourceOrderId, $type, $id, $now, $now->modify('+1 hour'));

        $attempt = $this->attempts->openFor(self::STORE, $sourceOrderId, $now);

        self::assertNotNull($attempt, 'the attempt should have been written');

        return $attempt->initiatedByName;
    }

    private function mirrorUser(int $adminId, string $username, string $userType): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_agents}}', [
            'admin_id' => $adminId,
            'username' => $username,
            'status' => 'active',
            'user_type' => $userType,
            'sync_hash' => str_repeat('a', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }
}
