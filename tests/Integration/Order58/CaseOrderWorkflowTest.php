<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Application\CaseOrders\CaseOrderImporter;
use App\Order58\Application\CaseOrders\CaseOrderParser;
use App\Order58\Application\CaseOrders\CaseOrderSyncCoordinator;
use App\Order58\Application\CaseOrders\CaseOrderSyncDrainer;
use App\Order58\Application\CaseOrders\CaseOrderSyncPolicy;
use App\Order58\Application\CaseOrders\CaseOrderSyncService;
use App\Order58\Client\CaseOrders\CaseOrderClientInterface;
use App\Order58\Client\CaseOrders\CaseOrderFetchFailed;
use App\Order58\Client\CaseOrders\CaseOrderResponse;
use App\Order58\Client\CaseOrders\CaseOrderSettings;
use App\Order58\Domain\CaseOrders\CaseOrderSyncStatus;
use App\Order58\Infrastructure\DbCaseOrderSyncRunRepository;
use App\Order58\Infrastructure\DbItemSyncStateRepository;
use App\Order58\Infrastructure\DbOrder58OrderItemRepository;
use App\Order58\Infrastructure\DbOrder58OrderRepository;
use App\Shared\Infrastructure\Db\DatabaseTarget;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function json_encode;

/**
 * The whole case order workflow against real MySQL: claim, worker, import, repeat.
 *
 * **No test here reaches the Order58 API.** The client is a double in every case, so "makes no live
 * call" is structural. That matters more than usual: the provider's permitted rate is unconfirmed, and
 * a suite that called it would spend whatever allowance exists.
 *
 * ## What this covers that the unit tests cannot
 *
 * The unit tests model the claim in memory. Here the claim is the real unique index on a generated
 * column, the import is the real upsert against real constraints, and "no duplicates" is a count of
 * rows rather than of calls to a fake.
 *
 * Fixture stores sit in the `9876559xx` range and every row is removed by the exact keys written.
 */
final class CaseOrderWorkflowTest extends Unit
{
    private const STORE = 987655980;
    private const ORDER_ONE = 919900001;
    private const ORDER_TWO = 919900002;

    private ConnectionInterface $connection;
    private DbCaseOrderSyncRunRepository $runs;
    private bool $ready = false;

    /** @var list<int> */
    private array $createdRunIds = [];

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        DatabaseTarget::resolve($this->connection)->assertLocalForSchemaChange();

        $this->ready = true;
        $this->runs = new DbCaseOrderSyncRunRepository($this->connection);
        $this->cleanup();
    }

    protected function _after(): void
    {
        if ($this->ready) {
            $this->cleanup();
        }
    }

    // ------------------------------------------------------------------ the end-to-end workflow

    /**
     * Claim in the request, work in the worker, rows in the tables.
     *
     * The shape the whole design rests on: a web request returns as soon as the run is claimed, and a
     * provider call never happens inside it.
     */
    public function testAClaimedRunIsExecutedByTheWorkerAndImportsItsOrders(): void
    {
        $client = $this->client($this->payload([self::ORDER_ONE]));
        $decision = $this->coordinator()->request('load-calls');

        $this->assertSame('started', $decision->outcome);
        $this->assertSame(0, $client->calls, 'claiming must not call the provider');
        $this->assertNotNull($decision->run);
        $this->createdRunIds[] = $decision->run->id;

        // The worker, exactly as `kf:worker:run` would drive it.
        $this->drainer($client)->drain(1);

        $this->assertSame(1, $client->calls, 'the worker made the one request');

        $run = $this->runs->find($decision->run->id);
        $this->assertSame(CaseOrderSyncStatus::Completed, $run?->status);
        $this->assertSame(1, $run->ordersInserted);
        $this->assertSame(1, $this->orderCount());
        $this->assertSame(1, $this->itemCount());
    }

    /**
     * **The button finishes the work on its own, with no worker pass at all.**
     *
     * This is the regression that broke the feature in the browser. The press claimed a run and left
     * the fetch to `kf:worker:run`, which is scheduled on no host here — so the run sat CLAIMED, the
     * orders never arrived, and the next press was refused on account of a run that was going nowhere.
     *
     * The drainer is deliberately NOT invoked below. Everything asserted here is what one HTTP request
     * does by itself, which is the only thing an operator with a browser can rely on.
     */
    public function testTheWebPressClaimsAndExecutesWithoutAnyWorkerPass(): void
    {
        $client = $this->client($this->payload([self::ORDER_ONE]));

        // Exactly what SyncAction does: decide, then carry it out in the same request.
        $decision = $this->coordinator()->request('manual', manual: true);

        $this->assertSame('started', $decision->outcome);
        $this->assertNotNull($decision->run);
        $this->createdRunIds[] = $decision->run->id;
        $this->assertSame(0, $client->calls, 'claiming alone must not call the provider');

        $outcome = $this->service($client)->execute($decision->run);

        $this->assertTrue($outcome->isSuccess());
        $this->assertSame(1, $client->calls, 'the press made the one request');

        $run = $this->runs->find($decision->run->id);
        $this->assertSame(CaseOrderSyncStatus::Completed, $run?->status, 'never left CLAIMED');
        $this->assertSame(1, $run->ordersInserted);
        $this->assertSame(1, $this->orderCount(), 'the orders are in the table, not pending a worker');
        $this->assertSame(1, $this->itemCount());
    }

    /**
     * And the button is free again immediately afterwards, with no waiting and no worker.
     *
     * The second half of the complaint: having synchronised, an administrator must be able to press
     * it again straight away. Nothing is claimed, nothing is held, so the next press starts a run.
     */
    public function testASecondPressStartsAnotherRunImmediately(): void
    {
        $client = $this->client($this->payload([self::ORDER_ONE]));

        $first = $this->coordinator()->request('manual', manual: true);
        $this->createdRunIds[] = $first->run?->id ?? 0;
        $this->service($client)->execute($first->run);

        $this->assertNull($this->runs->active(), 'nothing is left holding the claim');

        $second = $this->coordinator()->request('manual', manual: true);
        $this->createdRunIds[] = $second->run?->id ?? 0;

        $this->assertSame('started', $second->outcome);
        $this->assertNotSame($first->run?->id, $second->run?->id, 'and it is its own run');

        $this->service($client)->execute($second->run);

        // Same response twice: the second press must not duplicate a thing.
        $this->assertSame(1, $this->orderCount());
        $this->assertSame(1, $this->itemCount());
    }

    /**
     * **Morning then afternoon: new orders arrive, old ones are not duplicated.**
     *
     * The scenario the one-run-per-day index made impossible. Counted in rows, because "no duplicate
     * orders" is a property of the table and not of the importer's own bookkeeping.
     */
    public function testASecondRunImportsOnlyWhatIsNewAndDuplicatesNothing(): void
    {
        $morning = $this->coordinator()->request('load-calls');
        $this->createdRunIds[] = (int) $morning->run?->id;
        $this->drainer($this->client($this->payload([self::ORDER_ONE])))->drain(1);

        $this->assertSame(1, $this->orderCount());

        // Afternoon: the provider now returns the first order again, plus one more.
        $afternoon = $this->coordinator()->request('manual', manual: true);
        $this->assertSame('started', $afternoon->outcome, 'a second run on the same day is allowed');
        $this->createdRunIds[] = (int) $afternoon->run?->id;

        $this->drainer($this->client($this->payload([self::ORDER_ONE, self::ORDER_TWO])))->drain(1);

        $this->assertSame(2, $this->orderCount(), 'two orders, not three');

        $run = $this->runs->find((int) $afternoon->run?->id);
        $this->assertSame(1, $run?->ordersInserted, 'only the new one was inserted');
        $this->assertSame(1, $run->ordersUnchanged, 'the one already stored was left alone');
        $this->assertSame(0, $run->ordersUpdated);
    }

    /** Re-running the identical response writes nothing at all. */
    public function testRepeatingTheSameResponseTouchesNoRows(): void
    {
        $first = $this->coordinator()->request('manual', manual: true);
        $this->createdRunIds[] = (int) $first->run?->id;
        $this->drainer($this->client($this->payload([self::ORDER_ONE])))->drain(1);

        $before = $this->orderUpdatedAt();

        $second = $this->coordinator()->request('manual', manual: true);
        $this->createdRunIds[] = (int) $second->run?->id;
        $this->drainer($this->client($this->payload([self::ORDER_ONE])))->drain(1);

        $this->assertSame(1, $this->orderCount());
        $this->assertSame($before, $this->orderUpdatedAt(), 'the stored row was not rewritten');

        $run = $this->runs->find((int) $second->run?->id);
        $this->assertSame(1, $run?->ordersUnchanged);
        $this->assertSame(0, $run->ordersInserted);
    }

    // ------------------------------------------------------- no repeated calls, no duplicate work

    /**
     * Several callers at once produce ONE provider request.
     *
     * Four stores loading calls together is the realistic case, and the claim is a real unique index
     * here rather than a modelled one.
     */
    public function testConcurrentCallersShareOneRunAndOneRequest(): void
    {
        $coordinator = $this->coordinator();

        $first = $coordinator->request('load-calls');
        $this->createdRunIds[] = (int) $first->run?->id;

        $others = [];

        for ($i = 0; $i < 3; $i++) {
            $others[] = $coordinator->request('load-calls');
        }

        foreach ($others as $other) {
            $this->assertSame('joined', $other->outcome);
            $this->assertSame($first->run?->id, $other->run?->id);
        }

        $client = $this->client($this->payload([self::ORDER_ONE]));
        $this->drainer($client)->drain(1);

        $this->assertSame(1, $client->calls, 'one request for all four callers');
    }

    /** Inside the freshness window, Load Calls asks the provider nothing at all. */
    public function testLoadCallsReusesFreshDataWithoutCallingTheProvider(): void
    {
        $first = $this->coordinator()->request('load-calls');
        $this->createdRunIds[] = (int) $first->run?->id;

        $client = $this->client($this->payload([self::ORDER_ONE]));
        $this->drainer($client)->drain(1);

        // A second Load Calls moments later.
        $again = $this->coordinator(new CaseOrderSyncPolicy(
            minRefreshSeconds: 600,
            minProviderGapSeconds: 0,
        ))->request('load-calls');

        $this->assertSame('fresh', $again->outcome);
        $this->assertNull($this->runs->active(), 'nothing new was claimed');
        $this->assertSame(1, $client->calls, 'and nothing new was requested');
    }

    /** With nothing claimed, a worker pass is free and silent. */
    public function testAWorkerPassWithNothingClaimedRequestsNothing(): void
    {
        $client = $this->client($this->payload([self::ORDER_ONE]));

        $result = $this->drainer($client)->drain(1);

        $this->assertSame(0, $client->calls);
        $this->assertSame(0, $result->processed);
        $this->assertSame(0, $this->orderCount());
    }

    // -------------------------------------------------------------------------- failure handling

    /**
     * A provider failure fails that run and leaves the stored orders alone.
     *
     * And it is not retried: whether the request was transmitted is often unknowable, so retrying
     * automatically is how a provider with a strict rate receives two.
     */
    public function testAProviderFailureLeavesExistingOrdersIntactAndDoesNotRetry(): void
    {
        $good = $this->coordinator()->request('manual', manual: true);
        $this->createdRunIds[] = (int) $good->run?->id;
        $this->drainer($this->client($this->payload([self::ORDER_ONE])))->drain(1);
        $this->assertSame(1, $this->orderCount());

        $failing = $this->coordinator()->request('manual', manual: true);
        $this->createdRunIds[] = (int) $failing->run?->id;

        $client = $this->failingClient();
        $this->drainer($client)->drain(1);

        $run = $this->runs->find((int) $failing->run?->id);
        $this->assertSame(CaseOrderSyncStatus::Failed, $run?->status);
        $this->assertSame(1, $this->orderCount(), 'the order imported earlier is untouched');

        // A second worker pass must not try again: the run is settled, not pending.
        $this->drainer($client)->drain(1);
        $this->assertSame(1, $client->calls, 'no automatic retry');
    }

    // ------------------------------------------------------------------------------- fixtures

    private function coordinator(?CaseOrderSyncPolicy $policy = null): CaseOrderSyncCoordinator
    {
        return new CaseOrderSyncCoordinator(
            $this->runs,
            $this->settings(),
            $policy ?? new CaseOrderSyncPolicy(minRefreshSeconds: 0, minProviderGapSeconds: 0),
            new MutableClock('2026-10-26 13:00:00'),
        );
    }

    private function drainer(FakeClient $client): CaseOrderSyncDrainer
    {
        return new CaseOrderSyncDrainer(
            $this->service($client),
            $this->runs,
            new MutableClock('2026-10-26 13:00:05'),
            new NullLogger(),
        );
    }

    /** The service both the web press and the worker execute a claim through. */
    private function service(FakeClient $client): CaseOrderSyncService
    {
        $clock = new MutableClock('2026-10-26 13:00:05');

        return new CaseOrderSyncService(
            $client,
            $this->settings(),
            $this->runs,
            new CaseOrderParser(),
            new CaseOrderImporter(
                new DbOrder58OrderRepository($this->connection),
                new DbOrder58OrderItemRepository($this->connection),
                new DbItemSyncStateRepository($this->connection),
                $clock,
                new NullLogger(),
            ),
            $clock,
            new NullLogger(),
        );
    }

    private function settings(): CaseOrderSettings
    {
        return new CaseOrderSettings(
            url: 'https://order58.com/api/order-data/get-case-order-mixed',
            token: 'fixture-token',
        );
    }

    /** @param list<int> $orderIds @return list<array<array-key, mixed>> */
    private function payload(array $orderIds): array
    {
        $orders = [];

        foreach ($orderIds as $orderId) {
            $orders[] = [
                'id' => $orderId,
                'account_id' => self::STORE,
                'status' => 'completed',
                'total_amount' => '12.00000',
                'items' => [[
                    'id' => $orderId + 500,
                    'order_id' => $orderId,
                    'name' => 'Fixture item',
                    'qty' => 1,
                    'price' => '12.00000',
                ]],
            ];
        }

        return $orders;
    }

    /** @param list<array<array-key, mixed>> $orders */
    private function client(array $orders): FakeClient
    {
        return new FakeClient(new CaseOrderResponse(
            httpStatus: 200,
            responseBytes: 256,
            sanitizedBody: (string) json_encode($orders),
            orders: $orders,
        ));
    }

    private function failingClient(): FakeClient
    {
        return new FakeClient(null, CaseOrderFetchFailed::refused(503));
    }

    private function orderCount(): int
    {
        return (int) (new Query($this->connection))
            ->from('{{%order58_orders}}')
            ->where(['account_id' => self::STORE])
            ->count();
    }

    private function itemCount(): int
    {
        return (int) (new Query($this->connection))
            ->from('{{%order58_order_items}}')
            ->where(['store_account_id' => self::STORE])
            ->count();
    }

    private function orderUpdatedAt(): string
    {
        return (string) (new Query($this->connection))
            ->select('updated_at')
            ->from('{{%order58_orders}}')
            ->where(['account_id' => self::STORE, 'source_order_id' => self::ORDER_ONE])
            ->scalar();
    }

    /** The fixture store's rows, and the run ids this test created. Nothing else. */
    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%order58_order_items}}', ['store_account_id' => self::STORE])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => self::STORE])->execute();

        if ($this->createdRunIds !== []) {
            $this->connection->createCommand()
                ->delete('{{%order58_case_order_sync_runs}}', ['id' => $this->createdRunIds])->execute();
            $this->createdRunIds = [];
        }

        // Any run this suite's coordinator left active would block the next test's claim; it is one
        // of ours by construction, because a real run cannot be active while this suite runs.
        $active = $this->runs->active();

        if ($active !== null) {
            $this->connection->createCommand()
                ->delete('{{%order58_case_order_sync_runs}}', ['id' => $active->id])->execute();
        }
    }
}

/** Answers with a fixture and counts the asks. No socket, ever. */
final class FakeClient implements CaseOrderClientInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly ?CaseOrderResponse $response,
        private readonly ?CaseOrderFetchFailed $failure = null,
    ) {}

    public function fetch(): CaseOrderResponse
    {
        ++$this->calls;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        /** @var CaseOrderResponse */
        return $this->response;
    }
}
