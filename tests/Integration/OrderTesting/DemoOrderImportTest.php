<?php

declare(strict_types=1);

namespace App\Tests\Integration\OrderTesting;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Application\DemoOrderDirectory;
use App\OrderTesting\Application\DemoOrderImporter;
use App\OrderTesting\Application\TestAttemptService;
use App\OrderTesting\Domain\AttemptStatus;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Infrastructure\DbDemoOrderRepository;
use App\OrderTesting\Infrastructure\DbTestAttemptRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function dirname;
use function gmdate;
use function sort;
use function time;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * The importer and the attempt rules, against real MySQL.
 *
 * ## What is really being guarded
 *
 * Three things that would be invisible if they broke:
 *
 * 1. **Rescanning must be free.** The importer is meant to run every minute. If a second scan of an
 *    unchanged directory wrote rows, the demo-order table would grow without bound and every count on
 *    the store page would climb on its own.
 * 2. **Attribution is written once.** A rescan that re-credited would move one operator's work onto
 *    whoever clicked most recently, and nothing on the page would look wrong.
 * 3. **One source order, many demo orders.** Production proved the shape. A unique key on the wrong
 *    columns would silently keep only the newest, which reads as the feature working.
 */
final class DemoOrderImportTest extends Unit
{
    /** Far outside the mirrored range, so nothing here can collide with real data. */
    private const STORE = 1731;
    private const SOURCE_ORDER = 16758551;
    private const OTHER_SOURCE_ORDER = 16754561;

    private const USERNAME = '__kf_order_testing_import_admin__';

    private ConnectionInterface $connection;
    private DbDemoOrderRepository $demoOrders;
    private DbTestAttemptRepository $attempts;
    private int $adminId;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash('ImportTestPassw0rd!secure'));

        $this->adminId = (int) (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::USERNAME])->scalar();

        $this->demoOrders = new DbDemoOrderRepository($this->connection, new SystemClock());
        $this->attempts = new DbTestAttemptRepository($this->connection);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    private function importer(string $subdirectory = 'demo_mix_orders'): DemoOrderImporter
    {
        return new DemoOrderImporter(
            new DemoOrderDirectory(dirname(__DIR__, 2) . '/_data/order-testing/' . $subdirectory),
            $this->demoOrders,
            $this->attempts,
            new OrderNormalizer(),
            new SystemClock(),
            new NullLogger(),
        );
    }

    private function rowCount(): int
    {
        return (int) (new Query($this->connection))
            ->from('{{%order_testing_demo_orders}}')
            ->where(['store_source_id' => self::STORE])
            ->count();
    }

    // ------------------------------------------------------------------ one source order, many demos

    public function testOneSourceOrderKeepsEveryDemoOrderItProduced(): void
    {
        $this->importer()->import();

        $orders = $this->demoOrders->forSourceOrder(self::STORE, self::SOURCE_ORDER);

        $ids = [];
        foreach ($orders as $order) {
            $ids[] = $order->demoOrderId;
        }

        // Newest first, and all three. A later demo order never replaces an earlier one.
        sort($ids);
        assertSame([16630651, 16630661, 16630671], $ids);
    }

    public function testADemoOrderOfAnotherSourceOrderIsNotReturned(): void
    {
        $this->importer()->import();

        $counts = $this->demoOrders->countsFor(
            self::STORE,
            [self::SOURCE_ORDER, self::OTHER_SOURCE_ORDER],
        );

        assertSame(3, $counts[self::SOURCE_ORDER]);
        assertSame(1, $counts[self::OTHER_SOURCE_ORDER]);
    }

    public function testADemoOrderOfAnotherStoreIsNotReachable(): void
    {
        $this->importer()->import();

        // The fixtures all belong to store 1731. Asking as a different store must find nothing, which
        // is the predicate that stops a URL reaching another merchant's data.
        assertNull($this->demoOrders->findOne(self::STORE + 1, self::SOURCE_ORDER, 16630651));
        assertSame([], $this->demoOrders->forSourceOrder(self::STORE + 1, self::SOURCE_ORDER));
    }

    // ------------------------------------------------------------------ rescanning is free

    public function testScanningTheSameDirectoryTwiceWritesNothingTheSecondTime(): void
    {
        $first = $this->importer()->import();
        $after = $this->rowCount();

        $second = $this->importer()->import();

        assertSame(4, $first->inserted);
        assertSame(0, $second->inserted);
        assertSame(0, $second->updated);
        assertSame(4, $second->unchanged);
        assertSame($after, $this->rowCount(), 'a rescan must not create rows');
    }

    // ------------------------------------------------------------------ the gates, end to end

    public function testNothingFromTheRejectDirectoryIsStored(): void
    {
        $report = $this->importer('rejects')->import();

        // Five correctly named files, every one of them refused by a document gate.
        assertSame(5, $report->scanned);
        assertSame(0, $report->inserted);
        assertSame(5, $report->refused);
        assertSame(0, $this->rowCount());
    }

    public function testAMissingDirectoryImportsNothingAndDoesNotThrow(): void
    {
        $report = $this->importer('no_such_directory')->import();

        assertSame(0, $report->scanned);
        assertSame(0, $this->rowCount());
    }

    // ------------------------------------------------------------------ attribution

    public function testADemoOrderArrivingDuringAnOpenAttemptIsCreditedToIt(): void
    {
        $service = new TestAttemptService($this->attempts, new SystemClock(), 60);
        $outcome = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId);

        assertTrue($outcome->isAllowed());

        $this->importer()->import();

        foreach ($this->demoOrders->forSourceOrder(self::STORE, self::SOURCE_ORDER) as $order) {
            assertSame($outcome->attempt?->id, $order->matchedAttemptId, 'demo #' . $order->demoOrderId);
        }

        // The other source order had nothing open, so it stays honestly unattributed.
        $other = $this->demoOrders->forSourceOrder(self::STORE, self::OTHER_SOURCE_ORDER);
        assertNull($other[0]->matchedAttemptId);
    }

    public function testAnUnmatchedDemoOrderIsKeptRatherThanDiscarded(): void
    {
        $this->importer()->import();

        $orders = $this->demoOrders->forSourceOrder(self::STORE, self::SOURCE_ORDER);

        assertCount(3, $orders);
        foreach ($orders as $order) {
            assertNull($order->matchedAttemptId);
        }
    }

    /** A rescan must never move somebody else's work onto a newer attempt. */
    public function testRescanningDoesNotReattributeAnExistingDemoOrder(): void
    {
        $service = new TestAttemptService($this->attempts, new SystemClock(), 60);
        $first = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId);

        $this->importer()->import();

        // Close the first attempt the way time would, then let somebody else start one.
        $this->connection->createCommand()->update(
            '{{%order_testing_attempts}}',
            ['status' => AttemptStatus::Expired->value],
            ['id' => $first->attempt?->id],
        )->execute();

        $second = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId);
        assertNotSame($first->attempt?->id, $second->attempt?->id);

        $this->importer()->import();

        foreach ($this->demoOrders->forSourceOrder(self::STORE, self::SOURCE_ORDER) as $order) {
            assertSame($first->attempt?->id, $order->matchedAttemptId, 'attribution must not move');
        }
    }

    // ------------------------------------------------------------------ one attempt at a time

    public function testOnlyOneAttemptMayBeOpenForOneSourceOrder(): void
    {
        $service = new TestAttemptService($this->attempts, new SystemClock(), 60);

        $mine = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId);
        assertTrue($mine->isAllowed());

        // The same administrator clicking again resumes rather than creating a second row.
        $again = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId);
        assertTrue($again->isAllowed());
        assertSame($mine->attempt?->id, $again->attempt?->id);

        // A different administrator is refused, and told who holds it — not given an ambiguous second
        // open attempt, which would make every arriving demo order a coin toss between the two.
        $other = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId + 9_000_000);
        assertFalse($other->isAllowed());
        assertStringContainsString('already testing this order', (string) $other->refusal);

        assertSame(1, (int) (new Query($this->connection))
            ->from('{{%order_testing_attempts}}')
            ->where([
                'store_source_id' => self::STORE,
                'source_order_id' => self::SOURCE_ORDER,
                'status' => [AttemptStatus::Started->value, AttemptStatus::Matched->value],
            ])
            ->count());
    }

    /** A different source order is a different slot, so one test never blocks another. */
    public function testAnotherSourceOrderIsNotBlocked(): void
    {
        $service = new TestAttemptService($this->attempts, new SystemClock(), 60);

        assertTrue($service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId)->isAllowed());
        assertTrue(
            $service->start(self::STORE, self::OTHER_SOURCE_ORDER, InitiatorType::Admin, $this->adminId)->isAllowed(),
        );
    }

    /** Without this, one operator who clicked and walked away holds an order shut forever. */
    public function testAnExpiredAttemptReleasesTheSlot(): void
    {
        $service = new TestAttemptService($this->attempts, new SystemClock(), 60);
        $first = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId);

        $this->connection->createCommand()->update(
            '{{%order_testing_attempts}}',
            ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)],
            ['id' => $first->attempt?->id],
        )->execute();

        $other = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId + 9_000_000);

        assertTrue($other->isAllowed(), 'an expired attempt must not go on blocking');
        assertNotSame($first->attempt?->id, $other->attempt?->id);

        assertSame(AttemptStatus::Expired->value, (string) (new Query($this->connection))
            ->select('status')->from('{{%order_testing_attempts}}')
            ->where(['id' => $first->attempt?->id])->scalar());
    }

    /** Scoped to this suite's own fixtures, never a blanket delete. */
    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%order_testing_demo_orders}}', ['store_source_id' => [self::STORE, self::STORE + 1]])
            ->execute();
        $this->connection->createCommand()
            ->delete('{{%order_testing_attempts}}', ['store_source_id' => [self::STORE, self::STORE + 1]])
            ->execute();
        $this->connection->createCommand()
            ->delete('{{%admin_users}}', ['username' => self::USERNAME])->execute();
    }
}
