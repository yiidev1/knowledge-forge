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
 * The enqueue predicate, against real MySQL.
 *
 * ## What is really being guarded
 *
 * The balance this whole design rests on. Too narrow and historical orders never get their items —
 * which is the bug my first plan had, because it tied the enqueue to a write that unchanged orders
 * never reach. Too broad and every ordinary sync spends one API request per order, forever.
 *
 * So: an order the sync *saw* is queued when it has never been considered, and left alone when it is
 * already synced and its parent has not moved. Those two sentences are what these tests pin.
 */
final class ItemEnqueueTest extends Unit
{
    private const STORE = 987655930;

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

    /** The case my first design missed: an unchanged header says nothing about the items. */
    public function testAPendingOrderIsQueuedEvenWhenItsParentDidNotChange(): void
    {
        $this->seed(1, 'PENDING');

        // Passed as UNCHANGED — the list an enqueue tied to the write would never see.
        $queued = $this->repo->enqueue(self::STORE, [], [1], 3, $this->now());

        $this->assertSame(1, $queued);
        $this->assertSame('QUEUED', $this->itemStatus(1));
    }

    /** And the other half: a synced order with an unmoved parent costs nothing. */
    public function testASyncedOrderWithAnUnchangedParentIsLeftAlone(): void
    {
        $this->seed(1, 'SYNCED');

        $queued = $this->repo->enqueue(self::STORE, [], [1], 3, $this->now());

        $this->assertSame(0, $queued, 'An ordinary sync must not re-queue settled orders.');
        $this->assertSame('SYNCED', $this->itemStatus(1));
    }

    /** A moved parent is the one signal that the items may have moved too. */
    public function testASyncedOrderIsRequeuedWhenItsParentChanged(): void
    {
        $this->seed(1, 'SYNCED');
        $this->seed(2, 'EMPTY');

        $queued = $this->repo->enqueue(self::STORE, [1, 2], [], 3, $this->now());

        $this->assertSame(2, $queued);
        $this->assertSame('QUEUED', $this->itemStatus(1));
        $this->assertSame('QUEUED', $this->itemStatus(2));
    }

    /** Scope is the sync's own result set: an order it never saw is never touched. */
    public function testAnOrderOutsideTheSyncIsUntouched(): void
    {
        $this->seed(1, 'PENDING');
        $this->seed(2, 'PENDING');

        $this->repo->enqueue(self::STORE, [], [1], 3, $this->now());

        $this->assertSame('QUEUED', $this->itemStatus(1));
        $this->assertSame('PENDING', $this->itemStatus(2), 'Out of scope must mean untouched.');
    }

    public function testAFailedOrderIsRequeuedOnceItsRetryIsDue(): void
    {
        $this->seed(1, 'FAILED', attempts: 1, retryAfter: '2020-01-01 00:00:00');
        $this->seed(2, 'FAILED', attempts: 1, retryAfter: '2099-01-01 00:00:00');

        $this->repo->enqueue(self::STORE, [], [1, 2], 3, $this->now());

        $this->assertSame('QUEUED', $this->itemStatus(1));
        $this->assertSame('FAILED', $this->itemStatus(2), 'A retry that is not due must wait.');
    }

    /** Past the cap an order waits for a person, not for the next sync. */
    public function testAFailedOrderPastTheAttemptCapIsNotRequeued(): void
    {
        $this->seed(1, 'FAILED', attempts: 3, retryAfter: '2020-01-01 00:00:00');

        $this->repo->enqueue(self::STORE, [], [1], 3, $this->now());

        $this->assertSame('FAILED', $this->itemStatus(1));
    }

    /** Running the same sync twice must queue no duplicate work. */
    public function testQueuedAndProcessingOrdersAreNeverTouched(): void
    {
        $this->seed(1, 'QUEUED');
        $this->seed(2, 'PROCESSING');

        $queued = $this->repo->enqueue(self::STORE, [1, 2], [], 3, $this->now());

        $this->assertSame(0, $queued);
        $this->assertSame('QUEUED', $this->itemStatus(1));
        $this->assertSame('PROCESSING', $this->itemStatus(2), 'A live claim must not be disturbed.');
    }

    /** The queue read respects the retry clock and hands back oldest first. */
    public function testTheQueueOffersOnlyOrdersThatAreDue(): void
    {
        $this->seed(1, 'QUEUED');
        $this->seed(2, 'QUEUED', retryAfter: '2099-01-01 00:00:00');

        $due = $this->repo->nextQueued(10, $this->now());
        $ids = [];
        foreach ($due as $row) {
            if ($row['accountId'] === self::STORE) {
                $ids[] = $row['sourceOrderId'];
            }
        }

        $this->assertSame([1], $ids);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09 12:00:00');
    }

    private function itemStatus(int $sourceOrderId): string
    {
        return (string) (new Query($this->connection))
            ->select('items_status')
            ->from('{{%order58_orders}}')
            ->where(['account_id' => self::STORE, 'source_order_id' => $sourceOrderId])
            ->scalar();
    }

    private function seed(int $sourceOrderId, string $status, int $attempts = 0, ?string $retryAfter = null): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => self::STORE,
            'source_order_id' => $sourceOrderId,
            'status' => 'completed',
            'payload_json' => '{}',
            'content_hash' => str_repeat('a', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'items_status' => $status,
            'items_attempts' => $attempts,
            'items_retry_after' => $retryAfter,
        ])->execute();
    }

    /** Scoped to this suite's own fixture store, never a blanket delete. */
    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%order58_order_items}}', ['store_account_id' => self::STORE])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => self::STORE])->execute();
    }
}
