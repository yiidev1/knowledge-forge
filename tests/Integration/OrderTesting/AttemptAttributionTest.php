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

    // ------------------------------------------------------------------ attribution by creation time

    /**
     * The attempt that was running when the order was created is the one credited.
     *
     * This is the whole basis of attribution. The demo JSON carries a source order id and a demo
     * order id and nothing about who was at the keyboard, so the provider's creation timestamp
     * against the attempt windows is the only evidence that exists.
     */
    public function testTheAttemptRunningAtCreationTimeIsTheOneFound(): void
    {
        $start = new DateTimeImmutable('2026-10-07 13:00:00', new DateTimeZone('UTC'));
        $this->attempts->start(
            self::STORE,
            16900020,
            InitiatorType::Agent,
            $this->sharedId + 1,
            $start,
            $start->modify('+1 hour'),
        );

        $created = $start->modify('+20 minutes');
        $found = $this->attempts->attemptCoveringInstant(self::STORE, 16900020, $created);

        self::assertNotNull($found);
        self::assertSame(InitiatorType::Agent, $found->initiatedByType);
        self::assertSame($this->sharedId + 1, $found->initiatedById);
    }

    /**
     * The defect this replaced, pinned so it cannot come back.
     *
     * Attribution used to ask which attempt was open at **import** time. Imports are manual and the
     * measured gap in this database between an order being created and the button being pressed is
     * over a day, while an attempt lives an hour. So a two-day-old order would be credited to
     * whoever had clicked Practice Order most recently — a training record naming the wrong person.
     *
     * Here a demo is created inside one person's window and imported long after, while somebody
     * else has an attempt open. The lookup must still name the first person.
     */
    public function testADemoImportedLongAfterwardsIsNotCreditedToWhoeverIsPractisingNow(): void
    {
        $then = new DateTimeImmutable('2026-10-07 13:00:00', new DateTimeZone('UTC'));
        $this->attempts->start(
            self::STORE,
            16900021,
            InitiatorType::Agent,
            $this->sharedId + 1,
            $then,
            $then->modify('+1 hour'),
        );

        // Two days later, somebody else is practising the same order when the import runs.
        $later = $then->modify('+2 days');
        $this->attempts->start(
            self::STORE,
            16900021,
            InitiatorType::Admin,
            $this->sharedId,
            $later,
            $later->modify('+1 hour'),
        );

        $found = $this->attempts->attemptCoveringInstant(
            self::STORE,
            16900021,
            $then->modify('+10 minutes'),
        );

        self::assertNotNull($found);
        self::assertSame(InitiatorType::Agent, $found->initiatedByType, 'the creator, not the current user');
        self::assertSame($this->sharedId + 1, $found->initiatedById);
    }

    /** An order created when nobody was practising belongs to nobody. */
    public function testAnInstantInsideNoWindowIsUnmatched(): void
    {
        $start = new DateTimeImmutable('2026-10-07 13:00:00', new DateTimeZone('UTC'));
        $this->attempts->start(
            self::STORE,
            16900022,
            InitiatorType::Agent,
            $this->sharedId + 1,
            $start,
            $start->modify('+1 hour'),
        );

        self::assertNull($this->attempts->attemptCoveringInstant(
            self::STORE,
            16900022,
            $start->modify('+3 hours'),
        ));
    }

    /** The window is half-open, so two back-to-back attempts cannot both claim the boundary. */
    public function testTheInstantAnAttemptExpiresBelongsToTheNextOne(): void
    {
        $start = new DateTimeImmutable('2026-10-07 13:00:00', new DateTimeZone('UTC'));
        $expires = $start->modify('+1 hour');

        $this->attempts->start(
            self::STORE,
            16900023,
            InitiatorType::Agent,
            $this->sharedId + 1,
            $start,
            $expires,
        );
        $this->attempts->start(
            self::STORE,
            16900023,
            InitiatorType::Admin,
            $this->sharedId,
            $expires,
            $expires->modify('+1 hour'),
        );

        $found = $this->attempts->attemptCoveringInstant(self::STORE, 16900023, $expires);

        self::assertNotNull($found);
        self::assertSame(InitiatorType::Admin, $found->initiatedByType);
    }

    /** Another store's attempt never answers for this one, however well the times line up. */
    public function testAnotherStoresAttemptIsNeverTheAnswer(): void
    {
        $start = new DateTimeImmutable('2026-10-07 13:00:00', new DateTimeZone('UTC'));
        $this->attempts->start(
            self::STORE,
            16900024,
            InitiatorType::Agent,
            $this->sharedId + 1,
            $start,
            $start->modify('+1 hour'),
        );

        self::assertNull($this->attempts->attemptCoveringInstant(
            self::STORE + 1,
            16900024,
            $start->modify('+10 minutes'),
        ));
    }

    // ------------------------------------------------------------------ whose history is whose

    /**
     * An agent's history is narrowed in the QUERY, not after it.
     *
     * This is the authorization boundary for the Practice Attempts page. Filtering a full list in the
     * page would leave somebody else's attempts one forgotten `continue` away from being rendered;
     * narrowing here means their rows never leave the database at all.
     */
    public function testHistoryNarrowedToOneAgentExcludesEveryoneElse(): void
    {
        $this->attemptFor(16900030, InitiatorType::Agent, $this->sharedId + 1, '13:00');
        $this->attemptFor(16900030, InitiatorType::Agent, $this->sharedId + 2, '15:00');
        $this->attemptFor(16900030, InitiatorType::Admin, $this->sharedId, '17:00');

        $mine = $this->attempts->historyFor(
            self::STORE,
            16900030,
            InitiatorType::Agent,
            $this->sharedId + 1,
        );

        self::assertCount(1, $mine);
        self::assertSame($this->sharedId + 1, $mine[0]->initiatedById);
        self::assertSame(InitiatorType::Agent, $mine[0]->initiatedByType);
    }

    /** Both halves of the filter are required: an id alone must not match across realms. */
    public function testTheRealmIsPartOfTheFilterNotJustTheId(): void
    {
        // The three realms number their users separately, so one id can exist in all of them.
        $this->attemptFor(16900031, InitiatorType::Agent, $this->sharedId, '13:00');
        $this->attemptFor(16900031, InitiatorType::Admin, $this->sharedId, '15:00');

        $agentOnly = $this->attempts->historyFor(
            self::STORE,
            16900031,
            InitiatorType::Agent,
            $this->sharedId,
        );

        self::assertCount(1, $agentOnly);
        self::assertSame(InitiatorType::Agent, $agentOnly[0]->initiatedByType);
    }

    /** No filter means everybody, which is what the administrator surfaces ask for. */
    public function testUnfilteredHistoryReturnsEveryAttempt(): void
    {
        $this->attemptFor(16900032, InitiatorType::Agent, $this->sharedId + 1, '13:00');
        $this->attemptFor(16900032, InitiatorType::Admin, $this->sharedId, '15:00');

        self::assertCount(2, $this->attempts->historyFor(self::STORE, 16900032));
    }

    /**
     * Oldest first, so Try 1 stays Try 1.
     *
     * Numbering is taken from this order. A file imported late attaches to the attempt it was
     * created during and the numbers around it do not move — which is the whole reason the history
     * is built from attempts rather than from demo orders.
     */
    public function testHistoryIsOldestFirstSoTryNumbersAreStable(): void
    {
        $this->attemptFor(16900033, InitiatorType::Agent, $this->sharedId + 1, '09:00');
        $this->attemptFor(16900033, InitiatorType::Agent, $this->sharedId + 1, '11:00');
        $this->attemptFor(16900033, InitiatorType::Agent, $this->sharedId + 1, '13:00');

        $history = $this->attempts->historyFor(self::STORE, 16900033);

        self::assertCount(3, $history);
        self::assertTrue($history[0]->startedAt < $history[1]->startedAt);
        self::assertTrue($history[1]->startedAt < $history[2]->startedAt);
    }

    /** One store's history never answers for another's. */
    public function testHistoryIsScopedToOneStore(): void
    {
        $this->attemptFor(16900034, InitiatorType::Agent, $this->sharedId + 1, '13:00');

        self::assertSame([], $this->attempts->historyFor(self::STORE + 1, 16900034));
    }

    /** One attempt at a fixed hour, so windows never overlap and ordering is deterministic. */
    private function attemptFor(int $sourceOrderId, InitiatorType $type, int $actorId, string $hour): void
    {
        $at = new DateTimeImmutable('2026-10-07 ' . $hour . ':00', new DateTimeZone('UTC'));

        $this->attempts->start(self::STORE, $sourceOrderId, $type, $actorId, $at, $at->modify('+1 hour'));
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
