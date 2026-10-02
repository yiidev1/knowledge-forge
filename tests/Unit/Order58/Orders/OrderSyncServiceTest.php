<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Orders;

use App\Order58\Application\Orders\OrderMapper;
use App\Order58\Application\Orders\OrderDateRange;
use App\Order58\Application\Orders\OrderSyncService;
use App\Order58\Client\Orders\OrderDataClientInterface;
use App\Order58\Client\Orders\OrderDataFailed;
use App\Order58\Domain\Orders\Order58Order;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use App\Order58\Domain\Orders\OrderListQuery;
use App\Shared\Domain\Clock\ClockInterface;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function json_encode;

/**
 * The sync decision: insert what is new, update what moved, touch nothing that did not.
 * **No test here reaches a network or a database.**
 *
 * The client is an interface and the repository is an in-memory double, so "no live call" is structural
 * rather than a promise.
 */
final class OrderSyncServiceTest extends Unit
{
    private const ACCOUNT = 1141;

    public function testNewOrdersAreCreated(): void
    {
        [$service, $repo] = $this->service([$this->record('1'), $this->record('2')]);

        $summary = $service->sync(self::ACCOUNT, $this->range());

        $this->assertSame(2, $summary->received);
        $this->assertSame(2, $summary->created);
        $this->assertSame(0, $summary->updated);
        $this->assertSame(0, $summary->unchanged);
        $this->assertCount(2, $repo->stored);
    }

    /** **Idempotency.** The same response twice costs one write and then none. */
    public function testASecondIdenticalSyncWritesNothing(): void
    {
        [$service, $repo] = $this->service([$this->record('1'), $this->record('2')]);

        $service->sync(self::ACCOUNT, $this->range());
        $repo->writes = 0;
        $summary = $service->sync(self::ACCOUNT, $this->range());

        $this->assertSame(2, $summary->unchanged);
        $this->assertSame(0, $summary->created);
        $this->assertSame(0, $summary->updated);
        $this->assertSame(0, $repo->writes, 'An unchanged order must not be rewritten.');
    }

    public function testAChangedOrderIsUpdated(): void
    {
        [$service, $repo] = $this->service([$this->record('1')]);
        $service->sync(self::ACCOUNT, $this->range());

        $repo->writes = 0;
        $service->responses = [[$this->record('1', ['status' => 'cancelled'])]];
        $summary = $service->sync(self::ACCOUNT, $this->range());

        $this->assertSame(1, $summary->updated);
        $this->assertSame(0, $summary->created);
        $this->assertSame(1, $repo->writes);
    }

    public function testAnEmptyResponseIsNotAFailure(): void
    {
        [$service] = $this->service([]);

        $summary = $service->sync(self::ACCOUNT, $this->range());

        $this->assertSame(0, $summary->received);
        $this->assertSame(0, $summary->failed);
        $this->assertTrue($summary->isComplete());
    }

    /** One unusable record costs itself, never its neighbours. */
    public function testAMalformedRecordIsCountedAndTheRestStillSave(): void
    {
        [$service, $repo] = $this->service([
            $this->record('1'),
            $this->record(''),          // no usable id
            $this->record('3'),
        ]);

        $summary = $service->sync(self::ACCOUNT, $this->range());

        $this->assertSame(3, $summary->received);
        $this->assertSame(2, $summary->created);
        $this->assertSame(1, $summary->failed);
        $this->assertCount(2, $repo->stored);
        $this->assertNotSame([], $summary->errors);
    }

    /** A repeated id in one response resolves deterministically: the later copy wins, counted once. */
    public function testADuplicateIdInOneResponseIsStoredOnce(): void
    {
        [$service, $repo] = $this->service([
            $this->record('1', ['status' => 'pending']),
            $this->record('1', ['status' => 'completed']),
        ]);

        $summary = $service->sync(self::ACCOUNT, $this->range());

        $this->assertSame(2, $summary->received);
        $this->assertCount(1, $repo->stored);
        $this->assertSame('completed', $repo->stored[1]->status);
    }

    /** The counters must account for every received row, or a partial run reads as a complete one. */
    public function testTheCountersAlwaysAddUp(): void
    {
        [$service] = $this->service([$this->record('1'), $this->record(''), $this->record('3')]);

        $summary = $service->sync(self::ACCOUNT, $this->range());

        $this->assertSame($summary->received, $summary->processed());
        $this->assertTrue($summary->isFullyAccountedFor());
        // Accounted for, but not successful: one of the three failed.
        $this->assertFalse($summary->isComplete());
    }

    /**
     * A failing write is reported, never swallowed, and never silently claimed as saved.
     */
    public function testAFailedWriteIsCountedAsFailedNotCreated(): void
    {
        [$service, $repo] = $this->service([$this->record('1')]);
        $repo->failEverything = true;

        $summary = $service->sync(self::ACCOUNT, $this->range());

        $this->assertSame(0, $summary->created);
        $this->assertSame(1, $summary->failed);
        $this->assertFalse($summary->isComplete());
    }

    /**
     * The deadline stops the run and says so.
     *
     * A budget of zero makes the very first chunk check fail, which is the same branch a real overrun
     * takes — without the test having to actually burn 45 seconds.
     */
    public function testTheDeadlineStopsTheRunAndIsReported(): void
    {
        [$service, $repo] = $this->service([$this->record('1')], deadlineSeconds: 0);

        $summary = $service->sync(self::ACCOUNT, $this->range());

        $this->assertTrue($summary->stoppedEarly);
        $this->assertFalse($summary->isComplete(), 'A run that ran out of time must never look complete.');
        $this->assertSame(1, $summary->received);
        $this->assertSame([], $repo->stored);
    }

    /** A provider failure ends the run before anything is written — there is no partial state to explain. */
    public function testAProviderFailurePropagatesWithNothingWritten(): void
    {
        [$service, $repo] = $this->service([]);
        $service->throw = OrderDataFailed::refused(503);

        $this->expectException(OrderDataFailed::class);

        try {
            $service->sync(self::ACCOUNT, $this->range());
        } finally {
            $this->assertSame([], $repo->stored);
        }
    }

    // ------------------------------------------------------------------ harness

    /**
     * @param list<array<string, mixed>> $records
     * @return array{0: object, 1: object}
     */
    private function service(array $records, int $deadlineSeconds = 45): array
    {
        $repo = new class implements Order58OrderRepositoryInterface {
            /** @var array<int, Order58Order> */
            public array $stored = [];
            public int $writes = 0;
            public bool $failEverything = false;

            public function hashesFor(int $accountId, array $sourceOrderIds): array
            {
                $hashes = [];
                foreach ($sourceOrderIds as $id) {
                    if (isset($this->stored[$id])) {
                        $hashes[$id] = $this->stored[$id]->contentHash;
                    }
                }

                return $hashes;
            }

            public function upsert(Order58Order $order, DateTimeImmutable $now): bool
            {
                if ($this->failEverything) {
                    throw new \RuntimeException('write refused');
                }

                $this->writes++;
                $created = !isset($this->stored[$order->sourceOrderId]);
                $this->stored[$order->sourceOrderId] = $order;

                return $created;
            }

            public function page(OrderListQuery $query): array
            {
                return ['rows' => [], 'total' => 0];
            }
        };

        $client = new class ($records) implements OrderDataClientInterface {
            /** @var list<list<array<string, mixed>>> */
            public array $responses;
            public ?OrderDataFailed $throw = null;

            /** @param list<array<string, mixed>> $records */
            public function __construct(array $records)
            {
                $this->responses = [$records];
            }

            public function listOrders(int $accountId, string $dateFrom, string $dateTo): array
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return $this->responses[0];
            }
        };

        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-02 00:00:00', new DateTimeZone('UTC'));
            }
        };

        $service = new class ($client, $repo, $clock, $deadlineSeconds) {
            public array $responses;
            public ?OrderDataFailed $throw = null;
            private OrderSyncService $inner;

            public function __construct(
                private object $client,
                Order58OrderRepositoryInterface $repo,
                ClockInterface $clock,
                int $deadlineSeconds,
            ) {
                $this->inner = new OrderSyncService($client, $repo, new OrderMapper(), $clock, $deadlineSeconds);
                $this->responses = $client->responses;
            }

            public function sync(int $accountId, OrderDateRange $range)
            {
                $this->client->responses = $this->responses;
                $this->client->throw = $this->throw;

                return $this->inner->sync($accountId, $range);
            }
        };

        return [$service, $repo];
    }

    private function range(): OrderDateRange
    {
        [$range] = OrderDateRange::create('2026-09-30', '2026-10-01');

        return $range;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function record(string $id, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'status' => 'completed',
            'type' => 'delivery',
            'total_amount' => '18.42000',
            'created_at' => '1790914956',
            'data' => (string) json_encode(['reservation' => ['phone' => '13474472616']]),
        ];
    }
}
