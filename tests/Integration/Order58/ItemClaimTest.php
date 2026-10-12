<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Infrastructure\DbItemSyncStateRepository;
use App\Shared\Infrastructure\Db\DatabaseTarget;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function gmdate;
use function str_repeat;

/**
 * The claim predicate, against real MySQL. **No test here calls the Order58 API.**
 *
 * ## Why this has to be an integration test
 *
 * The claim is a single `UPDATE … WHERE` and its correctness *is* that WHERE clause — the status list,
 * and the live-lease disjunction `items_leased_until IS NULL OR items_leased_until < :now`. A double
 * that re-implements the predicate in PHP would be testing the double. The two things worth knowing
 * are whether exactly one of two racing callers wins, and whether a live lease really does refuse
 * both; neither is observable anywhere but in the database.
 *
 * The status lists are spelled out here rather than read from the service's private constants, so this
 * file states the intended contract independently. If somebody widens `MANUAL_CLAIMS` to include
 * `PROCESSING`, these tests should fail rather than silently follow.
 */
final class ItemClaimTest extends Unit
{
    private const STORE = 987655931;
    private const OTHER_STORE = 987655932;

    /** What the background drainer may take. */
    private const WORKER = ['QUEUED'];

    /** What a button press may take. Deliberately **not** `PROCESSING`. */
    private const MANUAL = ['PENDING', 'QUEUED', 'SYNCED', 'EMPTY', 'FAILED'];

    private const LEASE = 120;

    private ConnectionInterface $connection;
    private DbItemSyncStateRepository $repo;
    private bool $ready = false;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        DatabaseTarget::resolve($this->connection)->assertLocalForSchemaChange();

        $exists = (int) $this->connection->createCommand(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order58_orders'
             AND COLUMN_NAME = 'items_status'",
        )->queryScalar();

        if ($exists === 0) {
            $this->markTestSkipped('items_status does not exist — the migration has not been applied.');
        }

        $this->ready = true;
        $this->repo = new DbItemSyncStateRepository($this->connection);
        $this->cleanup();
    }

    protected function _after(): void
    {
        if ($this->ready) {
            $this->cleanup();
        }
    }

    // ------------------------------------------------------------------ the button jumps the queue

    /**
     * The behaviour the operator actually asked for.
     *
     * An order the enqueue left `QUEUED` is taken by the button *now*, not on the worker's next tick.
     */
    public function testAManualClaimTakesAQueuedOrderImmediately(): void
    {
        $this->seed(1, 'QUEUED');

        $state = $this->repo->claim(self::STORE, 1, 'manual-token', self::MANUAL, $this->now(), self::LEASE);

        $this->assertNotNull($state);
        $this->assertSame('manual-token', $state->leaseOwner);
        $this->assertSame('PROCESSING', $this->column(1, 'items_status'));
    }

    /** And having taken it, the worker must skip it — otherwise both would fetch the same order. */
    public function testTheWorkerSkipsAnOrderTheButtonAlreadyClaimed(): void
    {
        $this->seed(1, 'QUEUED');

        $this->repo->claim(self::STORE, 1, 'manual-token', self::MANUAL, $this->now(), self::LEASE);
        $worker = $this->repo->claim(self::STORE, 1, 'worker-token', self::WORKER, $this->now(), self::LEASE);

        $this->assertNull($worker, 'Two callers must never hold the same order.');
        $this->assertSame('manual-token', $this->column(1, 'items_lease_owner'));
    }

    /** A settled order can be refreshed on demand; the worker would leave it alone forever. */
    public function testAManualClaimCanRefreshASyncedOrderThatTheWorkerCannot(): void
    {
        $this->seed(1, 'SYNCED');

        $worker = $this->repo->claim(self::STORE, 1, 'worker-token', self::WORKER, $this->now(), self::LEASE);
        $manual = $this->repo->claim(self::STORE, 1, 'manual-token', self::MANUAL, $this->now(), self::LEASE);

        $this->assertNull($worker, 'An ordinary tick must not re-fetch settled orders.');
        $this->assertNotNull($manual);
    }

    /**
     * @dataProvider manuallyClaimable
     */
    public function testEveryRestingStatusIsManuallyClaimable(string $status): void
    {
        $this->seed(1, $status);

        $this->assertNotNull(
            $this->repo->claim(self::STORE, 1, 'manual-token', self::MANUAL, $this->now(), self::LEASE),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function manuallyClaimable(): iterable
    {
        foreach (self::MANUAL as $status) {
            yield $status => [$status];
        }
    }

    // ------------------------------------------------------------------ a live lease refuses everyone

    /** The second click, while the first request is still in flight. No API call follows a null. */
    public function testALiveLeaseRefusesASecondManualClaim(): void
    {
        $this->seed(1, 'QUEUED');
        $this->repo->claim(self::STORE, 1, 'first', self::MANUAL, $this->now(), self::LEASE);

        $second = $this->repo->claim(self::STORE, 1, 'second', self::MANUAL, $this->now(), self::LEASE);

        $this->assertNull($second);
        $this->assertSame('first', $this->column(1, 'items_lease_owner'));
    }

    /**
     * `PROCESSING` is absent from both lists, so an order mid-flight is unclaimable by status alone —
     * and the lease check means even a crashed holder's row is not stolen, only recovered.
     */
    public function testAProcessingOrderIsNotClaimableWhileItsLeaseIsLive(): void
    {
        $this->seed(1, 'PROCESSING', leaseOwner: 'holder', leasedUntil: '2026-10-09 12:01:00');

        $this->assertNull($this->repo->claim(self::STORE, 1, 'a', self::MANUAL, $this->now(), self::LEASE));
        $this->assertNull($this->repo->claim(self::STORE, 1, 'b', self::WORKER, $this->now(), self::LEASE));
        $this->assertSame('holder', $this->column(1, 'items_lease_owner'));
    }

    /**
     * An expired lease is **not** enough on its own: recovery has to run first.
     *
     * That is deliberate. `PROCESSING` is in neither status list, so a crashed holder's order goes back
     * through {@see DbItemSyncStateRepository::expireLeases()} — one place that invalidates the token —
     * rather than being picked up by whoever notices the clock first.
     */
    public function testAnExpiredLeaseStillNeedsRecoveryBeforeAnyoneCanClaim(): void
    {
        $this->seed(1, 'PROCESSING', leaseOwner: 'crashed', leasedUntil: '2026-10-09 11:00:00');

        $this->assertNull($this->repo->claim(self::STORE, 1, 'next', self::MANUAL, $this->now(), self::LEASE));

        $this->repo->expireLeases($this->now());

        $this->assertSame('QUEUED', $this->column(1, 'items_status'));
        $this->assertNull($this->column(1, 'items_lease_owner'), 'Recovery must invalidate the token.');
        $this->assertNotNull(
            $this->repo->claim(self::STORE, 1, 'next', self::WORKER, $this->now(), self::LEASE),
        );
    }

    // ------------------------------------------------------------------ the rest of the contract

    /** Two callers, one winner — the whole reason the precondition is in the WHERE clause. */
    public function testTwoClaimsOnTheSameOrderProduceExactlyOneWinner(): void
    {
        $this->seed(1, 'QUEUED');

        $first = $this->repo->claim(self::STORE, 1, 'a', self::WORKER, $this->now(), self::LEASE);
        $second = $this->repo->claim(self::STORE, 1, 'b', self::WORKER, $this->now(), self::LEASE);

        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    /** An attempt is spent at claim, so a process killed mid-request still bounds a poison order. */
    public function testAttemptsIncrementWhenTheClaimIsTakenNotWhenItFinishes(): void
    {
        $this->seed(1, 'QUEUED', attempts: 2);

        $state = $this->repo->claim(self::STORE, 1, 'a', self::WORKER, $this->now(), self::LEASE);

        $this->assertNotNull($state);
        $this->assertSame(3, $state->attempts);
        $this->assertSame('3', (string) $this->column(1, 'items_attempts'));
    }

    /** A refused claim must not have cost an attempt either — nothing was written at all. */
    public function testARefusedClaimDoesNotSpendAnAttempt(): void
    {
        $this->seed(1, 'SYNCED', attempts: 1);

        $this->assertNull($this->repo->claim(self::STORE, 1, 'a', self::WORKER, $this->now(), self::LEASE));
        $this->assertSame('1', (string) $this->column(1, 'items_attempts'));
    }

    /** The claim is scoped to the account, so one store's id can never reach another store's row. */
    public function testAClaimCannotCrossAccounts(): void
    {
        $this->seed(1, 'QUEUED');
        $this->seed(1, 'QUEUED', store: self::OTHER_STORE);

        $this->repo->claim(self::OTHER_STORE, 1, 'other', self::WORKER, $this->now(), self::LEASE);

        $this->assertSame('QUEUED', $this->column(1, 'items_status'));
        $this->assertNull($this->column(1, 'items_lease_owner'));
    }

    /** `hasSnapshot` is what lets the page say "stale snapshot kept" instead of "never synced". */
    public function testTheClaimReportsWhetherAPreviousSnapshotExists(): void
    {
        $this->seed(1, 'FAILED');
        $this->seed(2, 'FAILED', syncedAt: '2026-10-08 09:00:00');

        $never = $this->repo->claim(self::STORE, 1, 'a', self::MANUAL, $this->now(), self::LEASE);
        $stale = $this->repo->claim(self::STORE, 2, 'b', self::MANUAL, $this->now(), self::LEASE);

        $this->assertNotNull($never);
        $this->assertNotNull($stale);
        $this->assertFalse($never->hasSnapshot);
        $this->assertTrue($stale->hasSnapshot, 'A kept snapshot must be distinguishable from none.');
    }

    /** The lease is written forward from the caller's clock, not left to the database's. */
    public function testTheClaimWritesALeaseThatExpiresAfterTheLeaseWindow(): void
    {
        $this->seed(1, 'QUEUED');

        $this->repo->claim(self::STORE, 1, 'a', self::WORKER, $this->now(), self::LEASE);

        $this->assertSame('2026-10-09 12:02:00', (string) $this->column(1, 'items_leased_until'));
    }

    // ------------------------------------------------------------------ harness

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09 12:00:00');
    }

    private function column(int $sourceOrderId, string $column, int $store = self::STORE): mixed
    {
        /** @var mixed $value */
        $value = (new Query($this->connection))
            ->select($column)
            ->from('{{%order58_orders}}')
            ->where(['account_id' => $store, 'source_order_id' => $sourceOrderId])
            ->scalar();

        return $value;
    }

    private function seed(
        int $sourceOrderId,
        string $status,
        int $attempts = 0,
        ?string $leaseOwner = null,
        ?string $leasedUntil = null,
        ?string $syncedAt = null,
        int $store = self::STORE,
    ): void {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $sourceOrderId,
            'status' => 'completed',
            'payload_json' => '{}',
            'content_hash' => str_repeat('b', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'items_status' => $status,
            'items_attempts' => $attempts,
            'items_lease_owner' => $leaseOwner,
            'items_leased_until' => $leasedUntil,
            'items_synced_at' => $syncedAt,
        ])->execute();
    }

    /** Scoped to this suite's own fixture stores, never a blanket delete. */
    private function cleanup(): void
    {
        foreach ([self::STORE, self::OTHER_STORE] as $store) {
            $this->connection->createCommand()
                ->delete('{{%order58_order_items}}', ['store_account_id' => $store])->execute();
            $this->connection->createCommand()
                ->delete('{{%order58_orders}}', ['account_id' => $store])->execute();
        }
    }
}
