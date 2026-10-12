<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Orders;

use App\Order58\Application\Orders\OrderDateRange;
use App\Order58\Application\Orders\OrderMapper;
use App\Order58\Application\Orders\OrderSyncInProgress;
use App\Order58\Application\Orders\OrderSyncService;
use App\Order58\Client\Orders\OrderDataClientInterface;
use App\Order58\Client\Orders\OrderDataFailed;
use App\Order58\Domain\Orders\Order58Order;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use App\Order58\Domain\Orders\OrderListQuery;
use App\Shared\Domain\Clock\ClockInterface;
use App\Tests\Support\Fake\Order58\FakeOrderSyncLock;
use App\Tests\Support\Fake\Order58\RecordingItemSyncState;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use RuntimeException;

use function json_encode;

/**
 * The order-sync lock now belongs to the service, and these are the guarantees that move with it.
 *
 * ## What was actually wrong
 *
 * The lock lived in `SyncAction`, so it covered the button and nothing else.
 * `kf:order58:import-recordings` calls {@see OrderSyncService} directly after a settled recording batch
 * and acquired nothing — while the command's own docblock asserted the service held a lock. A scheduled
 * recording run and an administrator pressing "Sync orders" could therefore fetch, map and write the
 * same store and day at once.
 *
 * These tests pin the three things that make the move safe: a refusal costs no provider call, the lock
 * is released on **every** exit path, and the name is still the exact string the action used — a
 * different name would be a lock that locks nothing.
 *
 * **No test here reaches a network or a database.**
 */
final class OrderSyncLockTest extends Unit
{
    private const ACCOUNT = 1141;

    public function testTheLockNameIsStillTheOneTheActionUsed(): void
    {
        // The literal from SyncAction before the move. Written out rather than rebuilt from the same
        // sprintf, so a change to the format has to be made twice and noticed once.
        $this->assertSame(
            'kf-o58-orders-1141-2026-09-30-2026-10-01',
            OrderSyncService::lockName(self::ACCOUNT, $this->range()),
        );
    }

    public function testADifferentStoreOrRangeIsADifferentLock(): void
    {
        [$other] = OrderDateRange::create('2026-10-02', '2026-10-02');

        $this->assertNotSame(
            OrderSyncService::lockName(self::ACCOUNT, $this->range()),
            OrderSyncService::lockName(self::ACCOUNT + 1, $this->range()),
        );
        $this->assertNotSame(
            OrderSyncService::lockName(self::ACCOUNT, $this->range()),
            OrderSyncService::lockName(self::ACCOUNT, $other),
        );
    }

    /** A refused lock must cost nothing — above all, not a provider call. */
    public function testASecondSyncIsRefusedWithoutReachingTheProvider(): void
    {
        $lock = new FakeOrderSyncLock(granted: false);
        [$service, , $client] = $this->service($lock);

        try {
            $service->sync(self::ACCOUNT, $this->range());
            $this->fail('A held lock must refuse the second sync.');
        } catch (OrderSyncInProgress $e) {
            $this->assertSame(self::ACCOUNT, $e->accountId);
        }

        $this->assertSame(0, $client->calls, 'A refused sync must not reach the Orders API.');
        $this->assertSame([], $lock->held, 'Nothing was acquired, so nothing may be released.');
    }

    public function testTheLockIsReleasedAfterASuccessfulRun(): void
    {
        $lock = new FakeOrderSyncLock();
        [$service] = $this->service($lock);

        $service->sync(self::ACCOUNT, $this->range());

        $this->assertSame([OrderSyncService::lockName(self::ACCOUNT, $this->range())], $lock->acquired);
        $this->assertSame([], $lock->held, 'The lock must not outlive a successful run.');
    }

    /**
     * The path that matters most: the provider fails before anything is written.
     *
     * A lock leaked here would block this store and range until the connection closed — and on the web
     * tier that is the end of the request, so the next attempt a second later would be refused for no
     * reason anybody could see.
     */
    public function testTheLockIsReleasedWhenTheProviderFails(): void
    {
        $lock = new FakeOrderSyncLock();
        [$service, , $client] = $this->service($lock);
        $client->throw = true;

        try {
            $service->sync(self::ACCOUNT, $this->range());
            $this->fail('The provider failure must propagate.');
        } catch (OrderDataFailed) {
            // expected — the action turns this into its own message
        }

        $this->assertSame([], $lock->held, 'A provider failure must still release the lock.');
    }

    /** And on a failure nobody anticipated, which is what `finally` is for. */
    public function testTheLockIsReleasedWhenSomethingUnexpectedThrows(): void
    {
        $lock = new FakeOrderSyncLock();
        [$service, $repo] = $this->service($lock);
        $repo->explode = true;

        try {
            $service->sync(self::ACCOUNT, $this->range());
            $this->fail('The unexpected failure must propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame([], $lock->held, 'An unexpected throw must still release the lock.');
    }

    /**
     * @return array{OrderSyncService, object, object}
     */
    private function service(FakeOrderSyncLock $lock): array
    {
        $client = new class implements OrderDataClientInterface {
            public int $calls = 0;
            public bool $throw = false;

            public function listOrders(int $accountId, string $dateFrom, string $dateTo): array
            {
                ++$this->calls;

                if ($this->throw) {
                    throw OrderDataFailed::unreachable();
                }

                return [[
                    'id' => '1',
                    'status' => 'completed',
                    'type' => 'delivery',
                    'total_amount' => '18.42000',
                    'created_at' => '1790914956',
                    'data' => (string) json_encode(['reservation' => ['phone' => '13474472616']]),
                ]];
            }
        };

        $repo = new class implements Order58OrderRepositoryInterface {
            public bool $explode = false;

            public function hashesFor(int $accountId, array $sourceOrderIds): array
            {
                return [];
            }

            public function upsert(Order58Order $order, DateTimeImmutable $now): bool
            {
                if ($this->explode) {
                    // Not caught by the service's per-order guard: that one catches Throwable and
                    // notes it. This one has to escape, so an Error is used rather than an Exception.
                    throw new \Error('the database went away');
                }

                return true;
            }

            public function page(OrderListQuery $query): array
            {
                return ['rows' => [], 'total' => 0];
            }
        };

        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-05 12:00:00');
            }
        };

        return [
            new OrderSyncService(
                $client,
                $repo,
                new OrderMapper(),
                $clock,
                new NullLogger(),
                $lock,
                new RecordingItemSyncState(),
                new \App\Tests\Support\Fake\Order58\RecordingItemSyncBatches(),
                new \App\Order58\Application\ImportRunRequest('/dev/null', new NullLogger()),
                45,
                3,
            ),
            $repo,
            $client,
        ];
    }

    private function range(): OrderDateRange
    {
        [$range] = OrderDateRange::create('2026-09-30', '2026-10-01');

        return $range;
    }
}
