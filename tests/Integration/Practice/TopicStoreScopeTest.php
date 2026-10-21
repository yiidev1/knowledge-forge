<?php

declare(strict_types=1);

namespace App\Tests\Integration\Practice;

use App\AudioToText\Infrastructure\DbTopicAssignmentReader;
use App\Practice\Application\AssignTopicService;
use App\Practice\Application\TopicInputValidator;
use App\Practice\Application\TopicService;
use App\Practice\Domain\AssignedBy;
use App\Practice\Domain\AssignedByType;
use App\Practice\Infrastructure\DbSourceOrderExistence;
use App\Practice\Infrastructure\DbStoreOptions;
use App\Practice\Infrastructure\DbTopicAssignmentRepository;
use App\Practice\Infrastructure\DbTopicRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function gmdate;
use function str_repeat;

/**
 * A topic belongs to one store, or to all of them.
 *
 * Against real MySQL, because that is where the rules actually live: the unique key IS "one live name
 * per scope", and a unique key is the only thing that holds when two administrators save at once.
 *
 * Fixture ids sit in the `9876559xx` range, well outside anything mirrored, and the stores they name
 * are seeded into `order58_stores` and removed again — the service validates against that table, so a
 * test that did not seed them would be testing the refusal rather than the rule.
 */
final class TopicStoreScopeTest extends Unit
{
    private const STORE_A = 987655820;
    private const STORE_B = 987655821;
    private const ORDER_A = 16800411;
    private const ORDER_B = 16800412;

    private const NAMES = ['Scoped Refunds', 'Global Refunds', 'Store Only', 'Moved', 'Ambiguous'];

    private ConnectionInterface $connection;
    private DbTopicRepository $topics;
    private TopicService $topicService;
    private AssignTopicService $assignService;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $clock = new SystemClock();
        $this->topics = new DbTopicRepository($this->connection, $clock);
        $this->topicService = new TopicService(
            $this->topics,
            new TopicInputValidator(),
            new DbStoreOptions($this->connection),
        );
        $this->assignService = new AssignTopicService(
            $this->topics,
            new DbTopicAssignmentRepository($this->connection, $clock),
            new DbSourceOrderExistence($this->connection),
        );

        $this->seedStore(self::STORE_A, 'Scope Fixture A');
        $this->seedStore(self::STORE_B, 'Scope Fixture B');
        $this->seedOrder(self::STORE_A, self::ORDER_A);
        $this->seedOrder(self::STORE_B, self::ORDER_B);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ creating

    /** A topic created without a store is global, which is what every topic was before. */
    public function testATopicCreatedWithoutAStoreIsGlobal(): void
    {
        $id = $this->topicService->create('Global Refunds', null, true, null);
        $topic = $this->topics->find($id);

        $this->assertNotNull($topic);
        $this->assertNull($topic->storeSourceId);
        $this->assertTrue($topic->isGlobal());
    }

    /** And one created with a store keeps that store's own id — the mirror's, not a local row id. */
    public function testATopicCreatedForAStoreKeepsThatStoresSourceId(): void
    {
        $id = $this->topicService->create('Store Only', null, true, null, self::STORE_A);
        $topic = $this->topics->find($id);

        $this->assertNotNull($topic);
        $this->assertSame(self::STORE_A, $topic->storeSourceId);
        $this->assertFalse($topic->isGlobal());
        // And the listing resolves the name without a second query per row.
        $this->assertSame('Scope Fixture A', $topic->storeName);
    }

    /** A store nobody has mirrored is refused, however the request was made. */
    public function testAStoreThatDoesNotExistIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->topicService->create('Store Only', null, true, null, 987659999);
    }

    // ------------------------------------------------------------------ uniqueness per scope

    /** Two stores may each have a topic of the same name: they are two different topics. */
    public function testTwoStoresMayEachHaveATopicOfTheSameName(): void
    {
        $first = $this->topicService->create('Scoped Refunds', null, true, null, self::STORE_A);
        $second = $this->topicService->create('Scoped Refunds', null, true, null, self::STORE_B);

        $this->assertNotSame($first, $second);
        $this->assertSame(self::STORE_A, $this->topics->find($first)?->storeSourceId);
        $this->assertSame(self::STORE_B, $this->topics->find($second)?->storeSourceId);
    }

    /**
     * But one store may not have the same name twice — and neither may the global set.
     *
     * The second half is the one a plain `UNIQUE (name, store_id)` would have let through, because
     * MySQL treats two NULLs in a unique index as distinct. The generated column folds NULL to 0 so
     * that the key can refuse it.
     */
    public function testANameCannotRepeatWithinOneScope(): void
    {
        $this->topicService->create('Scoped Refunds', null, true, null, self::STORE_A);

        $this->expectException(ValidationException::class);
        $this->topicService->create('scoped   refunds', null, true, null, self::STORE_A);
    }

    public function testTwoGlobalTopicsStillCannotShareAName(): void
    {
        $this->topicService->create('Global Refunds', null, true, null);

        $this->expectException(ValidationException::class);
        $this->topicService->create('GLOBAL refunds', null, true, null);
    }

    /** A store topic may not take a global topic's name: the store would see both, identically named. */
    public function testAStoreTopicMayNotShadowAGlobalOne(): void
    {
        $this->topicService->create('Ambiguous', null, true, null);

        $this->expectException(ValidationException::class);
        $this->topicService->create('ambiguous', null, true, null, self::STORE_A);
    }

    // ------------------------------------------------------------------ what each store may use

    /** The rule the dropdown and the endpoint both apply: this store's topics, and the global ones. */
    public function testAStoreSeesItsOwnTopicsAndTheGlobalOnes(): void
    {
        $this->topicService->create('Global Refunds', null, true, null);
        $this->topicService->create('Store Only', null, true, null, self::STORE_A);
        $this->topicService->create('Scoped Refunds', null, true, null, self::STORE_B);

        $forA = $this->names($this->topics->assignable(self::STORE_A));
        $forB = $this->names($this->topics->assignable(self::STORE_B));

        $this->assertContains('Global Refunds', $forA);
        $this->assertContains('Store Only', $forA);
        $this->assertNotContains('Scoped Refunds', $forA);

        $this->assertContains('Global Refunds', $forB);
        $this->assertContains('Scoped Refunds', $forB);
        $this->assertNotContains('Store Only', $forB);
    }

    /*
     * The same rule, through the reader the Manage Orders page actually calls.
     *
     * `DbTopicRepository::assignable()` above is the Practice module's own query; the SET TOPIC
     * dropdown at `/order-testing/store/{id}` is filled by `DbTopicAssignmentReader`, which reads the
     * same tables **by name** because that module may not name this one. Two queries, one rule — so
     * both are tested, or the page could offer a topic the endpoint refuses.
     */
    public function testTheOrderPagesReaderOffersThisStoresTopicsAndTheGlobalOnes(): void
    {
        $this->topicService->create('Global Refunds', null, true, null);
        $this->topicService->create('Store Only', null, true, null, self::STORE_A);
        $this->topicService->create('Scoped Refunds', null, true, null, self::STORE_B);

        $reader = new DbTopicAssignmentReader($this->connection);

        $forA = $this->offered($reader, self::STORE_A);
        $forB = $this->offered($reader, self::STORE_B);

        $this->assertContains('Global Refunds', $forA);
        $this->assertContains('Store Only', $forA);
        $this->assertNotContains('Scoped Refunds', $forA);

        $this->assertContains('Global Refunds', $forB);
        $this->assertContains('Scoped Refunds', $forB);
        $this->assertNotContains('Store Only', $forB);
    }

    /** Navigating from one store to the next re-asks, so the list is never the previous store's. */
    public function testTheOrderPagesReaderNeverOffersAnotherStoresTopic(): void
    {
        $this->topicService->create('Store Only', null, true, null, self::STORE_A);

        $reader = new DbTopicAssignmentReader($this->connection);

        $this->assertNotContains('Store Only', $this->offered($reader, self::STORE_B));
    }

    /** Inactive is "kept, but not offered" — the dropdown is for new assignments only. */
    public function testTheOrderPagesReaderSkipsInactiveAndArchivedTopics(): void
    {
        $this->topicService->create('Global Refunds', null, false, null);
        $archived = $this->topicService->create('Store Only', null, true, null, self::STORE_A);
        $this->topicService->delete($archived);

        $offered = $this->offered(new DbTopicAssignmentReader($this->connection), self::STORE_A);

        $this->assertNotContains('Global Refunds', $offered);
        $this->assertNotContains('Store Only', $offered);
    }

    /**
     * An assignment made before a topic stopped being offered is history, and history stays.
     *
     * Retiring a topic is the reachable way to make an existing assignment ineligible — narrowing one
     * that another store is already using is refused outright, two tests below. The row stays, the
     * reader still reports it for the order, and it is simply no longer offered for a new one, which
     * is what lets the page show it as a historical choice instead of silently resetting the row to
     * "Select Topic".
     */
    public function testRetiringATopicLeavesEarlierAssignmentsReadable(): void
    {
        $id = $this->topicService->create('Moved', null, true, null);
        $this->assignService->assign(self::STORE_A, self::ORDER_A, $id, $this->actor());
        $this->topicService->deactivate($id);

        $reader = new DbTopicAssignmentReader($this->connection);
        $carried = $reader->forOrders(self::STORE_A, [(string) self::ORDER_A]);

        $this->assertArrayHasKey((string) self::ORDER_A, $carried);
        $this->assertSame('Moved', $carried[(string) self::ORDER_A]->name);
        $this->assertNotContains('Moved', $this->offered($reader, self::STORE_A));
    }

    /**
     * @return list<string>
     */
    private function offered(DbTopicAssignmentReader $reader, int $storeSourceId): array
    {
        $names = [];

        foreach ($reader->assignableTopics($storeSourceId) as $topic) {
            $names[] = $topic->name;
        }

        return $names;
    }

    // ------------------------------------------------------------------ assigning

    /** The enforcement that matters: a form is not a promise, so the endpoint checks too. */
    public function testAnotherStoresTopicCannotBeAssignedHere(): void
    {
        $id = $this->topicService->create('Store Only', null, true, null, self::STORE_A);

        $this->expectException(ValidationException::class);
        $this->assignService->assign(self::STORE_B, self::ORDER_B, $id, $this->actor());
    }

    public function testAGlobalTopicMayBeAssignedAnywhere(): void
    {
        $id = $this->topicService->create('Global Refunds', null, true, null);

        $this->assignService->assign(self::STORE_A, self::ORDER_A, $id, $this->actor());
        $this->assignService->assign(self::STORE_B, self::ORDER_B, $id, $this->actor());

        $this->assertSame(2, $this->topics->assignmentCount($id));
    }

    // ------------------------------------------------------------------ changing the scope

    /** Narrowing a topic nobody has used elsewhere is fine. */
    public function testATopicWithNoForeignAssignmentsMayBeNarrowed(): void
    {
        $id = $this->topicService->create('Moved', null, true, null);
        $this->assignService->assign(self::STORE_A, self::ORDER_A, $id, $this->actor());

        $this->topicService->update($id, 'Moved', null, null, self::STORE_A, true);

        $this->assertSame(self::STORE_A, $this->topics->find($id)?->storeSourceId);
        // The assignment is untouched: it was always this store's.
        $this->assertSame(1, $this->topics->assignmentCount($id));
    }

    /**
     * Narrowing one that another store is already using is refused, and nothing changes.
     *
     * The alternative — detaching those assignments — would rewrite somebody's training record to
     * make a form submission succeed.
     */
    public function testNarrowingATopicAnotherStoreUsesIsRefused(): void
    {
        $id = $this->topicService->create('Moved', null, true, null);
        $this->assignService->assign(self::STORE_B, self::ORDER_B, $id, $this->actor());

        try {
            $this->topicService->update($id, 'Moved', null, null, self::STORE_A, true);
            $this->fail('narrowing should have been refused');
        } catch (ValidationException) {
            // Expected.
        }

        $this->assertNull($this->topics->find($id)?->storeSourceId, 'the topic must still be global');
        $this->assertSame(1, $this->topics->assignmentCount($id), 'the assignment must survive');
    }

    /** Widening is always safe: global covers every assignment a store topic can have. */
    public function testAStoreTopicMayBeMadeGlobalAgain(): void
    {
        $id = $this->topicService->create('Moved', null, true, null, self::STORE_A);
        $this->assignService->assign(self::STORE_A, self::ORDER_A, $id, $this->actor());

        $this->topicService->update($id, 'Moved', null, null, null, true);

        $this->assertNull($this->topics->find($id)?->storeSourceId);
        $this->assertSame(1, $this->topics->assignmentCount($id));
    }

    /** An edit that says nothing about the store leaves it where it is. */
    public function testAnEditThatOmitsTheStoreDoesNotMakeTheTopicGlobal(): void
    {
        $id = $this->topicService->create('Store Only', null, true, null, self::STORE_A);

        $this->topicService->update($id, 'Store Only', 'renamed description');

        $this->assertSame(self::STORE_A, $this->topics->find($id)?->storeSourceId);
    }

    // ------------------------------------------------------------------ helpers

    /** @param list<\App\Practice\Domain\Topic> $topics @return list<string> */
    private function names(array $topics): array
    {
        $out = [];

        foreach ($topics as $topic) {
            $out[] = $topic->name;
        }

        return $out;
    }

    private function actor(): AssignedBy
    {
        return new AssignedBy(AssignedByType::Admin, 1);
    }

    private function seedStore(int $sourceId, string $name): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => $name,
            'active' => 1,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedOrder(int $store, int $order): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $order,
            'payload_json' => '{}',
            'content_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** Scoped to this test's own stores and names; never a blanket delete. */
    private function cleanup(): void
    {
        foreach ([self::STORE_A, self::STORE_B] as $store) {
            $this->connection->createCommand()
                ->delete('{{%practice_order_topics}}', ['store_source_id' => $store])->execute();
            $this->connection->createCommand()
                ->delete('{{%order58_orders}}', ['account_id' => $store])->execute();
            $this->connection->createCommand()
                ->delete('{{%order58_stores}}', ['source_id' => $store])->execute();
        }

        $this->connection->createCommand()
            ->delete('{{%practice_topics}}', ['name' => self::NAMES])->execute();
    }
}
