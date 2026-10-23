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
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\NullLogger;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_unique;
use function dirname;
use function gmdate;
use function sort;
use function time;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The importer and the attempt rules, against real MySQL.
 *
 * ## What is really being guarded
 *
 * Three things that would be invisible if they broke:
 *
 * 1. **Rescanning must be free.** An administrator presses "Sync demo orders" whenever they want to,
 *    including twice in a row. If a second scan of an unchanged directory wrote rows, the demo-order
 *    table would grow with every press and every count on the store page would climb on its own.
 * 2. **Attribution is written once.** A rescan that re-credited would move one operator's work onto
 *    whoever clicked most recently, and nothing on the page would look wrong.
 * 3. **One source order, many demo orders.** Production proved the shape. A unique key on the wrong
 *    columns would silently keep only the newest, which reads as the feature working.
 */
final class DemoOrderImportTest extends Unit
{
    /** Far outside the mirrored range, so nothing here can collide with real data. */
    /**
     * A fixture store id, deliberately far outside the mirrored range.
     *
     * This was a REAL store id, and {@see cleanup()} deletes every demo order belonging to it. The
     * fixture documents carry the same filenames and demo order ids as the real ones, so nothing
     * could tell the two apart and each run of this suite destroyed the real store's demo orders.
     */
    private const STORE = 987655920;
    private const SOURCE_ORDER = 16758551;

    /**
     * The `created_at` every fixture document carries, as a unix timestamp.
     *
     * Attribution is decided against this instant, not against when the importer runs, so a test
     * that wants a demo order credited has to put an attempt window around THIS.
     */
    private const FIXTURE_CREATED = 1791353759;
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

    /**
     * One attempt whose window contains `$instant`, returning its id.
     *
     * Times are passed explicitly rather than taken from the clock, because the fixtures' creation
     * timestamp is fixed in the past and attribution is decided against it.
     */
    private function attemptCovering(int $sourceOrderId, int $instant, ?int $actorId = null): int
    {
        $at = (new DateTimeImmutable('@' . $instant))->setTimezone(new DateTimeZone('UTC'));

        $start = $this->attempts->start(
            self::STORE,
            $sourceOrderId,
            InitiatorType::Admin,
            $actorId ?? $this->adminId,
            $at->modify('-10 minutes'),
            $at->modify('+50 minutes'),
        );

        $id = $start->attempt?->id;
        assertNotNull($id, 'the attempt should have been written');

        return $id;
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

        // The fixtures all belong to one fixture store. Asking as a different store must find nothing, which
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

    /**
     * A demo order is credited to the attempt that was running when it was CREATED.
     *
     * This test used to start an attempt and import immediately, asserting that an order "arriving
     * during an open attempt" was credited to it. That described the old rule, and the old rule was
     * wrong: imports are manual and run long after the fact, so arrival time says nothing about who
     * was practising. The window below is placed around the fixture's own `created_at` instead,
     * which is the evidence attribution actually rests on.
     */
    public function testADemoOrderCreatedDuringAnAttemptIsCreditedToIt(): void
    {
        $attemptId = $this->attemptCovering(self::SOURCE_ORDER, self::FIXTURE_CREATED);

        $this->importer()->import();

        foreach ($this->demoOrders->forSourceOrder(self::STORE, self::SOURCE_ORDER) as $order) {
            assertSame($attemptId, $order->matchedAttemptId, 'demo #' . $order->demoOrderId);
        }

        // The other source order had no attempt covering its creation, so it stays honestly
        // unattributed — a blank Tested By rather than a guess.
        $other = $this->demoOrders->forSourceOrder(self::STORE, self::OTHER_SOURCE_ORDER);
        assertNull($other[0]->matchedAttemptId);
    }

    /**
     * An attempt that merely happens to be open at import time credits nothing.
     *
     * The failure mode this replaced: a demo order created days ago, imported while somebody else
     * has just clicked Practice Order, and credited to them. An honest blank is better than a
     * training record naming the wrong person.
     */
    public function testAnAttemptOpenOnlyAtImportTimeIsNotCredited(): void
    {
        $service = new TestAttemptService($this->attempts, new SystemClock(), 60);
        $outcome = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId);

        assertTrue($outcome->isAllowed());

        $this->importer()->import();

        foreach ($this->demoOrders->forSourceOrder(self::STORE, self::SOURCE_ORDER) as $order) {
            assertNull($order->matchedAttemptId, 'demo #' . $order->demoOrderId);
        }
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

    /**
     * A rescan must never move somebody else's work onto a newer attempt.
     *
     * Deliberately set up so the lookup itself could not decide: a SECOND attempt is backdated over
     * the same creation instant, which makes the evidence ambiguous. Attribution must still not
     * move, because an order that already exists is updated rather than inserted and only an insert
     * credits anybody. Ambiguity may leave a new order unmatched; it may never rewrite an old one.
     */
    public function testRescanningDoesNotReattributeAnExistingDemoOrder(): void
    {
        $first = $this->attemptCovering(self::SOURCE_ORDER, self::FIXTURE_CREATED);

        $this->importer()->import();

        // Close the first attempt the way time would, then backdate a second over the same instant.
        $this->connection->createCommand()->update(
            '{{%order_testing_attempts}}',
            ['status' => AttemptStatus::Expired->value],
            ['id' => $first],
        )->execute();

        $second = $this->attemptCovering(self::SOURCE_ORDER, self::FIXTURE_CREATED);
        assertNotSame($first, $second);

        $this->importer()->import();

        foreach ($this->demoOrders->forSourceOrder(self::STORE, self::SOURCE_ORDER) as $order) {
            assertSame($first, $order->matchedAttemptId, 'attribution must not move');
        }
    }

    // ------------------------------------------------------------------ one attempt at a time

    /**
     * Several people may practise one source order at once; one person may not stack attempts.
     *
     * Inverted deliberately: this used to assert that a second person was REFUSED. That was never
     * the business rule — it was how attribution was kept unambiguous, by making overlapping
     * attempts impossible. The rule is now concurrent practice, and the cost is paid at import,
     * where two attempts covering one creation instant leave the order Unmatched rather than
     * guessing between them.
     *
     * What the lock still does is stop one person stacking attempts by pressing twice or racing two
     * tabs, which is asserted first.
     */
    public function testEachPersonGetsTheirOwnAttemptAndNobodyStacksTheirs(): void
    {
        $service = new TestAttemptService($this->attempts, new SystemClock(), 60);

        $mine = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId);
        assertTrue($mine->isAllowed());

        // The same administrator clicking again resumes rather than creating a second row.
        $again = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId);
        assertTrue($again->isAllowed());
        assertSame($mine->attempt?->id, $again->attempt?->id);

        // A different person is no longer turned away, and gets an attempt of their own.
        $other = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId + 9_000_000);
        assertTrue($other->isAllowed(), 'concurrent practice is the business rule now');
        assertNotSame($mine->attempt?->id, $other->attempt?->id);

        // Two people, two rows — not one shared slot, and not four from the repeated clicks.
        assertSame(2, (int) (new Query($this->connection))
            ->from('{{%order_testing_attempts}}')
            ->where([
                'store_source_id' => self::STORE,
                'source_order_id' => self::SOURCE_ORDER,
                'status' => [AttemptStatus::Started->value, AttemptStatus::Matched->value],
            ])
            ->count());
    }

    /**
     * And the consequence, stated: overlapping attempts make an arriving demo order Unmatched.
     *
     * Two windows cover the fixture's creation instant, so the lookup has two candidates and answers
     * null. The order is kept and shown as Unmatched rather than credited to a coin toss.
     */
    public function testADemoCreatedWhileTwoPeoplePractisedIsLeftUnmatched(): void
    {
        // Two DIFFERENT people: the same person pressing twice resumes one attempt, which is
        // exactly the case that must stay unambiguous.
        $this->attemptCovering(self::SOURCE_ORDER, self::FIXTURE_CREATED);
        $this->attemptCovering(self::SOURCE_ORDER, self::FIXTURE_CREATED, $this->adminId + 9_000_000);

        $this->importer()->import();

        foreach ($this->demoOrders->forSourceOrder(self::STORE, self::SOURCE_ORDER) as $order) {
            assertNull($order->matchedAttemptId, 'demo #' . $order->demoOrderId);
        }
    }

    /**
     * All three realms practising one source order at the same time, and one number in two of them.
     *
     * The case above proves two *administrators* are no longer turned away. This proves the realms
     * are independent of one another, which is a different claim: an administrator, an operation user
     * and an agent each hold their own attempt on the same order at the same moment.
     *
     * The second half is the one that would bite. `admin_users.id` and Order58's `admin_id` are
     * separate id spaces, and they overlap in this installation for real — `order58_agents.admin_id
     * = 1` and `admin_users.id = 1` are two different people. If the lock compared the id without the
     * realm, the agent who happens to share an administrator's number would be handed that
     * administrator's attempt: their work would be filed under somebody else's name, and the page
     * would look perfectly normal.
     */
    public function testTheThreeRealmsPractiseTheSameOrderSideBySide(): void
    {
        $service = new TestAttemptService($this->attempts, new SystemClock(), 60);

        $admin = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Admin, $this->adminId);
        $operation = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Operation, 171);
        $agent = $service->start(self::STORE, self::SOURCE_ORDER, InitiatorType::Agent, 171);

        foreach (['administrator' => $admin, 'operation user' => $operation, 'agent' => $agent] as $who => $start) {
            assertTrue($start->isAllowed(), $who . ' must not be turned away');
            assertNotNull($start->attempt, $who . ' must get an attempt');
        }

        // Three rows, not one shared slot — and the operation user and the agent share the number
        // 171 without sharing the attempt.
        $ids = [$admin->attempt?->id, $operation->attempt?->id, $agent->attempt?->id];
        assertCount(3, array_unique($ids), 'each realm holds its own attempt');

        assertSame(3, (int) (new Query($this->connection))
            ->from('{{%order_testing_attempts}}')
            ->where([
                'store_source_id' => self::STORE,
                'source_order_id' => self::SOURCE_ORDER,
                'status' => [AttemptStatus::Started->value, AttemptStatus::Matched->value],
            ])
            ->count());

        // And each row says which realm it belongs to, because the id alone does not identify anybody.
        $realms = (new Query($this->connection))
            ->select('initiated_by_type')
            ->from('{{%order_testing_attempts}}')
            ->where(['store_source_id' => self::STORE, 'source_order_id' => self::SOURCE_ORDER])
            ->column();
        sort($realms);

        assertSame(['ADMIN', 'AGENT', 'OPERATION'], $realms);
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
