<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Items;

use App\Order58\Application\Items\ItemSyncOutcome;
use App\Order58\Application\Items\OrderItemSyncDrainer;
use App\Order58\Application\Items\OrderItemSyncInterface;
use App\Order58\Domain\Items\ItemSyncState;
use App\Order58\Domain\Items\ItemSyncStateRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use RuntimeException;

use function array_shift;

/**
 * The drainer that runs inside the existing `kf:worker:run`.
 *
 * ## What is really being guarded
 *
 * That it behaves like a good citizen of a shared worker: bounded by the limit it is given, never
 * fatal to the other drainers, and honest about what counts as progress. `Busy` is the subtle one —
 * an order somebody else holds is neither processed nor failed, and counting it either way would make
 * the worker's exit code lie about a perfectly normal collision with an administrator's button press.
 */
final class OrderItemSyncDrainerTest extends Unit
{
    public function testItIsNamedForLogging(): void
    {
        $this->assertSame('order58-order-items', $this->drainer()->name());
    }

    public function testRecoveryReturnsStrandedOrdersToTheQueue(): void
    {
        $state = new QueueDouble();
        $state->expired = 2;

        $this->drainer($state)->recover();

        $this->assertSame(1, $state->expireCalls);
    }

    /** Recovery failing must not stop the drain, nor the other drainers in the list. */
    public function testRecoveryFailureIsSwallowed(): void
    {
        $state = new QueueDouble();
        $state->throwOnExpire = true;

        $this->drainer($state)->recover();

        $this->assertTrue(true, 'recover() must not propagate.');
    }

    public function testEachQueuedOrderIsSynced(): void
    {
        $state = new QueueDouble();
        $state->queued = [
            ['accountId' => 1731, 'sourceOrderId' => 1],
            ['accountId' => 1731, 'sourceOrderId' => 2],
        ];
        $sync = new SyncDouble([ItemSyncOutcome::synced(3), ItemSyncOutcome::empty()]);

        $result = $this->drainer($state, $sync)->drain(10);

        $this->assertSame(2, $result->processed);
        $this->assertSame(0, $result->failed);
        $this->assertSame([[1731, 1], [1731, 2]], $sync->calls);
    }

    /** The limit is the worker's budget, and it belongs to the queue read. */
    public function testTheLimitIsPassedToTheQueue(): void
    {
        $state = new QueueDouble();

        $this->drainer($state)->drain(7);

        $this->assertSame(7, $state->lastLimit);
    }

    /**
     * A held order is neither progress nor failure.
     *
     * The commonest cause is an administrator pressing "Sync Order Items" on an order the worker had
     * queued. Counting that as failure would make the worker exit non-zero on an ordinary Tuesday.
     */
    public function testABusyOrderIsNeitherProcessedNorFailed(): void
    {
        $state = new QueueDouble();
        $state->queued = [['accountId' => 1731, 'sourceOrderId' => 1]];
        $sync = new SyncDouble([ItemSyncOutcome::busy()]);

        $result = $this->drainer($state, $sync)->drain(10);

        $this->assertSame(0, $result->processed);
        $this->assertSame(0, $result->failed);
    }

    /**
     * An unavailable installation stops the batch and counts nothing.
     *
     * The state is global, so the second candidate would answer identically. Counting it as failure
     * would make the worker exit non-zero purely because a feature has not been switched on, and
     * continuing would log the same sentence once per queued order.
     */
    public function testAnUnavailableInstallationStopsTheBatchWithoutCountingAnything(): void
    {
        $state = new QueueDouble();
        $state->queued = [
            ['accountId' => 1731, 'sourceOrderId' => 1],
            ['accountId' => 1731, 'sourceOrderId' => 2],
        ];
        $sync = new SyncDouble([
            ItemSyncOutcome::unavailable('Original Order Items synchronization is not enabled or configured.'),
            ItemSyncOutcome::synced(1),
        ]);

        $result = $this->drainer($state, $sync)->drain(10);

        $this->assertSame(0, $result->processed);
        $this->assertSame(0, $result->failed);
        $this->assertCount(1, $sync->calls, 'The rest of the batch must not be attempted.');
    }

    /** A failed order is both: it reached a terminal state, and that state is a failure. */
    public function testAFailedOrderCountsAsProcessedAndFailed(): void
    {
        $state = new QueueDouble();
        $state->queued = [['accountId' => 1731, 'sourceOrderId' => 1]];
        $sync = new SyncDouble([ItemSyncOutcome::failed('nope')]);

        $result = $this->drainer($state, $sync)->drain(10);

        $this->assertSame(1, $result->processed);
        $this->assertSame(1, $result->failed);
    }

    /** One order throwing must not cost the rest of the batch. */
    public function testOneThrowingOrderDoesNotStopTheBatch(): void
    {
        $state = new QueueDouble();
        $state->queued = [
            ['accountId' => 1731, 'sourceOrderId' => 1],
            ['accountId' => 1731, 'sourceOrderId' => 2],
        ];
        $sync = new SyncDouble([new RuntimeException('boom'), ItemSyncOutcome::synced(1)]);

        $result = $this->drainer($state, $sync)->drain(10);

        $this->assertSame(1, $result->processed);
        $this->assertSame(1, $result->failed);
        $this->assertCount(2, $sync->calls, 'The second order must still be attempted.');
    }

    /** An unreadable queue is a quiet no-op, not a crash that takes the worker down. */
    public function testAnUnreadableQueueDrainsNothing(): void
    {
        $state = new QueueDouble();
        $state->throwOnRead = true;

        $result = $this->drainer($state)->drain(10);

        $this->assertSame(0, $result->processed);
        $this->assertSame(0, $result->failed);
    }

    private function drainer(?QueueDouble $state = null, ?SyncDouble $sync = null): OrderItemSyncDrainer
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-09 12:00:00');
            }
        };

        return new OrderItemSyncDrainer(
            $state ?? new QueueDouble(),
            $sync ?? new SyncDouble([]),
            $clock,
            new NullLogger(),
        );
    }
}

/** A queue that answers with whatever the test put in it, and can refuse to answer at all. */
final class QueueDouble implements ItemSyncStateRepositoryInterface
{
    /** @var list<array{accountId: int, sourceOrderId: int}> */
    public array $queued = [];
    public int $expired = 0;
    public int $expireCalls = 0;
    public ?int $lastLimit = null;
    public bool $throwOnRead = false;
    public bool $throwOnExpire = false;

    public function statusesFor(int $accountId, array $sourceOrderIds): array
    {
        return [];
    }

    public function nextQueued(int $limit, DateTimeImmutable $now): array
    {
        $this->lastLimit = $limit;

        if ($this->throwOnRead) {
            throw new RuntimeException('the queue is unreadable');
        }

        return $this->queued;
    }

    public function expireLeases(DateTimeImmutable $now): int
    {
        ++$this->expireCalls;

        if ($this->throwOnExpire) {
            throw new RuntimeException('recovery failed');
        }

        return $this->expired;
    }

    public function enqueue(int $a, array $c, array $u, int $m, DateTimeImmutable $now): int
    {
        return 0;
    }

    public function claim(int $a, int $o, string $l, array $f, DateTimeImmutable $n, int $s): ?ItemSyncState
    {
        return null;
    }

    public function markSynced(int $a, int $o, string $l, int $c, string $h, string $j, DateTimeImmutable $n): void {}

    public function markFailed(int $a, int $o, string $l, string $e, ?DateTimeImmutable $r, DateTimeImmutable $n): void {}
}

/** Returns queued outcomes in order; a Throwable in the list is thrown instead. */
final class SyncDouble implements OrderItemSyncInterface
{
    /** @var list<array{int, int}> */
    public array $calls = [];

    /** @param list<ItemSyncOutcome|RuntimeException> $outcomes */
    public function __construct(private array $outcomes) {}

    public function syncOrder(int $accountId, int $sourceOrderId, bool $manual = false): ItemSyncOutcome
    {
        $this->calls[] = [$accountId, $sourceOrderId];
        $next = array_shift($this->outcomes) ?? ItemSyncOutcome::busy();

        if ($next instanceof RuntimeException) {
            throw $next;
        }

        return $next;
    }
}
