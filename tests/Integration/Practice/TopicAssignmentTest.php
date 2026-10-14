<?php

declare(strict_types=1);

namespace App\Tests\Integration\Practice;

use App\Practice\Application\AssignTopicService;
use App\Practice\Application\TopicInputValidator;
use App\Practice\Application\TopicService;
use App\Practice\Domain\AssignedBy;
use App\Practice\Domain\AssignedByType;
use App\Practice\Domain\TopicStatus;
use App\Practice\Infrastructure\DbSourceOrderExistence;
use App\Practice\Infrastructure\DbTopicAssignmentRepository;
use App\Practice\Infrastructure\DbTopicRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function gmdate;

/**
 * The rules that only the database can actually enforce.
 *
 * Each of these could be written in PHP and would then be true until two requests arrived at once.
 * They are asserted here against a real MySQL because that is the only place they are true: the unique
 * key on `(store_source_id, source_order_id)` IS "one topic per source order", and the unique key on
 * `live_name_key` IS "no two live topics share a name".
 *
 * Fixtures use store ids in the `9876559xx` range, far outside the mirrored range, and are cleaned up
 * by id rather than by truncation so a shared development database survives.
 */
final class TopicAssignmentTest extends Unit
{
    private const STORE = 987655810;
    private const OTHER_STORE = 987655811;
    private const ORDER_A = 16800401;
    private const ORDER_B = 16758551;
    private const ORDER_OTHER_STORE = 16754561;

    private ConnectionInterface $connection;
    private DbTopicRepository $topics;
    private DbTopicAssignmentRepository $assignments;
    private TopicService $topicService;
    private AssignTopicService $assignService;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $clock = new SystemClock();
        $this->topics = new DbTopicRepository($this->connection, $clock);
        $this->assignments = new DbTopicAssignmentRepository($this->connection, $clock);
        $this->topicService = new TopicService($this->topics, new TopicInputValidator());
        $this->assignService = new AssignTopicService(
            $this->topics,
            $this->assignments,
            new DbSourceOrderExistence($this->connection),
        );

        $this->seedOrder(self::STORE, self::ORDER_A);
        $this->seedOrder(self::STORE, self::ORDER_B);
        $this->seedOrder(self::OTHER_STORE, self::ORDER_OTHER_STORE);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    // ---------------------------------------------------------------- topics

    public function testTwoLiveTopicsCannotShareANameHoweverItIsSpelled(): void
    {
        $this->topicService->create('Change Dish', null, true, null);

        $this->expectException(ValidationException::class);
        $this->topicService->create('  CHANGE   dish ', null, true, null);
    }

    public function testArchivingATopicFreesItsNameForReuse(): void
    {
        $first = $this->topicService->create('Change Dish', null, true, null);
        $this->topicService->delete($first);

        // The same name again: allowed, because the archived row's live key is NULL.
        $second = $this->topicService->create('Change Dish', null, true, null);

        self::assertNotSame($first, $second);
        self::assertSame(TopicStatus::Archived, $this->topics->find($first)?->status);
        self::assertSame(TopicStatus::Active, $this->topics->find($second)?->status);
    }

    public function testArchivingKeepsExistingAssignmentsAndTheirName(): void
    {
        $topic = $this->topicService->create('Sushi Any Roll 2', null, true, null);
        $this->assignService->assign(self::STORE, self::ORDER_A, $topic, $this->admin());

        $this->topicService->delete($topic);

        $still = $this->assignments->forOrders(self::STORE, [self::ORDER_A]);

        self::assertArrayHasKey(self::ORDER_A, $still);
        self::assertSame('Sushi Any Roll 2', $still[self::ORDER_A]->topicName);
        self::assertSame(TopicStatus::Archived, $still[self::ORDER_A]->topicStatus);
    }

    public function testAnArchivedTopicCannotBeAssignedToAnythingNew(): void
    {
        $topic = $this->topicService->create('Retired', null, true, null);
        $this->topicService->delete($topic);

        $this->expectException(ValidationException::class);
        $this->assignService->assign(self::STORE, self::ORDER_A, $topic, $this->admin());
    }

    public function testAnInactiveTopicCannotBeAssignedEither(): void
    {
        $topic = $this->topicService->create('Paused', null, false, null);

        $this->expectException(ValidationException::class);
        $this->assignService->assign(self::STORE, self::ORDER_A, $topic, $this->admin());
    }

    public function testOnlyActiveTopicsAreOfferedForAssignment(): void
    {
        $active = $this->topicService->create('Offered', null, true, null);
        $inactive = $this->topicService->create('Paused', null, false, null);
        $archived = $this->topicService->create('Gone', null, true, null);
        $this->topicService->delete($archived);

        $ids = [];

        foreach ($this->topics->assignable() as $topic) {
            $ids[] = $topic->id;
        }

        self::assertContains($active, $ids);
        self::assertNotContains($inactive, $ids);
        self::assertNotContains($archived, $ids);
    }

    // ---------------------------------------------------------------- assignment

    public function testOneSourceOrderCarriesExactlyOneTopicAndReassignmentReplacesIt(): void
    {
        $first = $this->topicService->create('First', null, true, null);
        $second = $this->topicService->create('Second', null, true, null);

        $this->assignService->assign(self::STORE, self::ORDER_A, $first, $this->admin());
        $this->assignService->assign(self::STORE, self::ORDER_A, $second, $this->admin());

        self::assertSame(1, $this->rowCount(self::STORE, self::ORDER_A), 'reassignment made a second row');

        $held = $this->assignments->forOrders(self::STORE, [self::ORDER_A]);
        self::assertSame($second, $held[self::ORDER_A]->topicId);
    }

    public function testAssigningTheSameTopicTwiceIsIdempotent(): void
    {
        $topic = $this->topicService->create('Twice', null, true, null);

        $this->assignService->assign(self::STORE, self::ORDER_A, $topic, $this->admin());
        $this->assignService->assign(self::STORE, self::ORDER_A, $topic, $this->admin());

        self::assertSame(1, $this->rowCount(self::STORE, self::ORDER_A));
    }

    public function testBulkAssignmentWritesEveryOrderAndDeduplicatesWhatWasPosted(): void
    {
        $topic = $this->topicService->create('Bulk', null, true, null);

        $written = $this->assignService->assignMany(
            self::STORE,
            [self::ORDER_A, self::ORDER_B, self::ORDER_A],
            $topic,
            $this->admin(),
        );

        self::assertSame(2, $written, 'the repeated id should have been collapsed');
        self::assertCount(2, $this->assignments->forOrders(self::STORE, [self::ORDER_A, self::ORDER_B]));
    }

    public function testAnEmptySelectionIsRefusedRatherThanSilentlyDoingNothing(): void
    {
        $topic = $this->topicService->create('Bulk', null, true, null);

        $this->expectException(ValidationException::class);
        $this->assignService->assignMany(self::STORE, [], $topic, $this->admin());
    }

    /**
     * The security case: an order id that belongs to another store, posted to this store's endpoint.
     *
     * It must be refused outright, and nothing at all may be written — not even the orders in the same
     * request that were legitimate.
     */
    public function testAnOrderFromAnotherStoreIsRefusedAndNothingIsWritten(): void
    {
        $topic = $this->topicService->create('Bulk', null, true, null);

        try {
            $this->assignService->assignMany(
                self::STORE,
                [self::ORDER_A, self::ORDER_OTHER_STORE],
                $topic,
                $this->admin(),
            );
            self::fail('An order from another store should have been refused.');
        } catch (ValidationException) {
            // expected
        }

        self::assertSame(0, $this->rowCount(self::STORE, self::ORDER_A), 'a partial write happened');
        self::assertSame(0, $this->rowCount(self::OTHER_STORE, self::ORDER_OTHER_STORE));
    }

    public function testAnOrderThatExistsNowhereIsRefused(): void
    {
        $topic = $this->topicService->create('Bulk', null, true, null);

        $this->expectException(ValidationException::class);
        $this->assignService->assign(self::STORE, 99999999, $topic, $this->admin());
    }

    public function testAnOperationUserIsRecordedAsOperationNotAsAnAdministrator(): void
    {
        $topic = $this->topicService->create('By operation', null, true, null);

        $this->assignService->assign(
            self::STORE,
            self::ORDER_A,
            $topic,
            new AssignedBy(AssignedByType::Operation, 4321),
        );

        $row = (new Query($this->connection))
            ->from('{{%practice_order_topics}}')
            ->where(['store_source_id' => self::STORE, 'source_order_id' => self::ORDER_A])
            ->one();

        self::assertSame('OPERATION', $row['assigned_by_type']);
        self::assertSame(4321, (int) $row['assigned_by_id']);
    }

    public function testUnassigningLeavesTheOrderWithNoTopic(): void
    {
        $topic = $this->topicService->create('Temporary', null, true, null);
        $this->assignService->assign(self::STORE, self::ORDER_A, $topic, $this->admin());

        $this->assignService->unassign(self::STORE, self::ORDER_A, $this->admin());

        self::assertSame([], $this->assignments->forOrders(self::STORE, [self::ORDER_A]));
    }

    public function testTheFilterReturnsOnlyThisStoresOrdersForThatTopic(): void
    {
        $topic = $this->topicService->create('Filtered', null, true, null);
        $other = $this->topicService->create('Elsewhere', null, true, null);

        $this->assignService->assign(self::STORE, self::ORDER_A, $topic, $this->admin());
        $this->assignService->assign(self::STORE, self::ORDER_B, $other, $this->admin());
        $this->assignService->assign(self::OTHER_STORE, self::ORDER_OTHER_STORE, $topic, $this->admin());

        self::assertSame([self::ORDER_A], $this->assignments->orderIdsWithTopic(self::STORE, $topic));
    }

    public function testTheAssignmentCountIsWhatTheArchiveWarningWillShow(): void
    {
        $topic = $this->topicService->create('Counted', null, true, null);

        $this->assignService->assignMany(self::STORE, [self::ORDER_A, self::ORDER_B], $topic, $this->admin());

        self::assertSame(2, $this->topics->assignmentCount($topic));
        self::assertSame(2, $this->topicService->delete($topic), 'delete should report what it kept');
    }

    // ---------------------------------------------------------------- fixtures

    private function admin(): AssignedBy
    {
        return new AssignedBy(AssignedByType::Admin, 1);
    }

    private function rowCount(int $store, int $order): int
    {
        return (int) (new Query($this->connection))
            ->from('{{%practice_order_topics}}')
            ->where(['store_source_id' => $store, 'source_order_id' => $order])
            ->count();
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

    /** Scoped to this test's own stores and its own topic names; never a blanket delete. */
    private function cleanup(): void
    {
        foreach ([self::STORE, self::OTHER_STORE] as $store) {
            $this->connection->createCommand()
                ->delete('{{%practice_order_topics}}', ['store_source_id' => $store])->execute();
            $this->connection->createCommand()
                ->delete('{{%order58_orders}}', ['account_id' => $store])->execute();
        }

        $this->connection->createCommand()->delete('{{%practice_topics}}', [
            'name' => [
                'Change Dish', 'Sushi Any Roll 2', 'Retired', 'Paused', 'Offered', 'Gone',
                'First', 'Second', 'Twice', 'Bulk', 'By operation', 'Temporary', 'Filtered',
                'Elsewhere', 'Counted',
            ],
        ])->execute();
    }
}
