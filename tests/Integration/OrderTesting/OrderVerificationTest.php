<?php

declare(strict_types=1);

namespace App\Tests\Integration\OrderTesting;

use App\AudioToText\Infrastructure\DbOrderVerificationReader;
use App\OrderTesting\Application\OrderVerificationService;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\TestActorInterface;
use App\OrderTesting\Domain\VerificationAction;
use App\OrderTesting\Domain\VerificationConflict;
use App\OrderTesting\Infrastructure\DbOrderVerificationRepository;
use App\OrderTesting\Infrastructure\DbSourceOrderExistence;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function gmdate;
use function str_repeat;

/**
 * One verification per source order, shared by both realms, with a history that cannot be rewritten.
 *
 * Asserted against a real MySQL because that is where the rules actually live: the UNIQUE key on
 * `(store_source_id, source_order_id)` IS "one verification per order", and the conditional UPDATE on
 * `version` IS "a stale screen cannot undo somebody else's decision". Both are true in PHP only until
 * two requests arrive at once.
 *
 * Fixture store ids sit in the `9876559xx` range, far outside the mirrored range, and cleanup is by id
 * rather than truncation so a shared development database survives.
 */
final class OrderVerificationTest extends Unit
{
    private const STORE = 987655870;
    private const OTHER_STORE = 987655871;
    private const ORDER_A = 16800401;
    private const ORDER_B = 16758551;

    /** The same number in two stores, which is the collision the key exists for. */
    private const SHARED_NUMBER = 16754561;

    private const ADMIN_ID = 4242;
    private const OPERATION_ID = 7;

    private ConnectionInterface $connection;
    private DbOrderVerificationRepository $repository;
    private OrderVerificationService $service;
    private DbOrderVerificationReader $reader;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->repository = new DbOrderVerificationRepository($this->connection);
        $this->service = new OrderVerificationService(
            $this->repository,
            new DbSourceOrderExistence($this->connection),
            new SystemClock(),
        );
        $this->reader = new DbOrderVerificationReader($this->connection, new AppTimeZone());

        $this->seedOrder(self::STORE, self::ORDER_A);
        $this->seedOrder(self::STORE, self::ORDER_B);
        $this->seedOrder(self::STORE, self::SHARED_NUMBER);
        $this->seedOrder(self::OTHER_STORE, self::SHARED_NUMBER);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    // ---------------------------------------------------------------- the two realms, one record

    public function testAnOrderNobodyHasTouchedIsUnverified(): void
    {
        $state = $this->service->current(self::STORE, self::ORDER_A);

        self::assertFalse($state->isVerified);
        self::assertSame(0, $state->version);
        self::assertNull($state->verifiedAt);
        self::assertSame(0, $this->stateRows(), 'an untouched order should not need a row');
    }

    public function testAnAdministratorVerifiesAndAnOperationUserSeesTheSameRecord(): void
    {
        $this->service->apply(self::STORE, self::ORDER_A, VerificationAction::Verified, $this->admin(), 0);

        // Read back through a *different* actor's eyes. Nothing about the reader is part of the key.
        $seen = $this->service->current(self::STORE, self::ORDER_A);

        self::assertTrue($seen->isVerified);
        self::assertSame(InitiatorType::Admin, $seen->verifiedByType);
        self::assertSame(self::ADMIN_ID, $seen->verifiedById);
        self::assertSame(1, $this->stateRows(), 'verification must not be stored per realm');
    }

    public function testAnOperationUserVerifiesAndAnAdministratorSeesTheSameRecord(): void
    {
        $this->service->apply(
            self::STORE,
            self::ORDER_A,
            VerificationAction::Verified,
            $this->operation(),
            0,
        );

        $seen = $this->service->current(self::STORE, self::ORDER_A);

        self::assertTrue($seen->isVerified);
        self::assertSame(InitiatorType::Operation, $seen->verifiedByType);
        // Order58's own admin_id, which is what the actor port carries — never `order58_agents.id`.
        self::assertSame(self::OPERATION_ID, $seen->verifiedById);
        self::assertSame(1, $this->stateRows());
    }

    public function testEitherRealmCanUndoTheOther(): void
    {
        $afterAdmin = $this->service->apply(
            self::STORE,
            self::ORDER_A,
            VerificationAction::Verified,
            $this->admin(),
            0,
        );

        $afterOperation = $this->service->apply(
            self::STORE,
            self::ORDER_A,
            VerificationAction::Unverified,
            $this->operation(),
            $afterAdmin->version,
        );

        self::assertFalse($afterOperation->isVerified);
        self::assertNull($afterOperation->verifiedByType, 'nobody is the current verifier any more');
        self::assertNull($afterOperation->verifiedAt);
        self::assertSame(InitiatorType::Operation, $afterOperation->changedByType);
        self::assertSame(self::OPERATION_ID, $afterOperation->changedById);

        // And back again, by the administrator, over the operation user's removal.
        $again = $this->service->apply(
            self::STORE,
            self::ORDER_A,
            VerificationAction::Verified,
            $this->admin(),
            $afterOperation->version,
        );

        self::assertTrue($again->isVerified);
        self::assertSame(InitiatorType::Admin, $again->verifiedByType);
    }

    // ---------------------------------------------------------------- the history

    public function testEveryChangeIsKeptInOrderWithItsOwnActor(): void
    {
        $one = $this->service->apply(self::STORE, self::ORDER_A, VerificationAction::Verified, $this->admin(), 0);
        $two = $this->service->apply(
            self::STORE,
            self::ORDER_A,
            VerificationAction::Unverified,
            $this->operation(),
            $one->version,
        );
        $this->service->apply(
            self::STORE,
            self::ORDER_A,
            VerificationAction::Verified,
            $this->operation(),
            $two->version,
        );

        $events = (new Query($this->connection))
            ->select(['action', 'actor_type', 'actor_id'])
            ->from('{{%order_testing_verification_events}}')
            ->where(['store_source_id' => self::STORE, 'source_order_id' => self::ORDER_A])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        self::assertSame(
            [
                ['action' => 'VERIFIED', 'actor_type' => 'ADMIN', 'actor_id' => (string) self::ADMIN_ID],
                ['action' => 'UNVERIFIED', 'actor_type' => 'OPERATION', 'actor_id' => (string) self::OPERATION_ID],
                ['action' => 'VERIFIED', 'actor_type' => 'OPERATION', 'actor_id' => (string) self::OPERATION_ID],
            ],
            $events,
            'the sequence is the whole reason the history exists; the state row only knows the last one',
        );
    }

    // ---------------------------------------------------------------- identity

    public function testTheSameOrderNumberInTwoStoresIsTwoDifferentDecisions(): void
    {
        $this->service->apply(
            self::STORE,
            self::SHARED_NUMBER,
            VerificationAction::Verified,
            $this->admin(),
            0,
        );

        self::assertTrue($this->service->current(self::STORE, self::SHARED_NUMBER)->isVerified);
        self::assertFalse(
            $this->service->current(self::OTHER_STORE, self::SHARED_NUMBER)->isVerified,
            'an order number is not unique across stores; the pair is the identity',
        );
    }

    public function testOrdersAreIndependentOfEachOther(): void
    {
        $this->service->apply(self::STORE, self::ORDER_A, VerificationAction::Verified, $this->admin(), 0);

        self::assertFalse($this->service->current(self::STORE, self::ORDER_B)->isVerified);
    }

    public function testAnOrderFromAnotherStoreIsRefusedAndNothingIsWritten(): void
    {
        try {
            // A store this caller may reach, and an order that is not its own.
            $this->service->apply(
                self::STORE,
                999999999,
                VerificationAction::Verified,
                $this->admin(),
                0,
            );
            self::fail('An order outside this store should have been refused.');
        } catch (ValidationException) {
            // expected
        }

        self::assertSame(0, $this->stateRows());
        self::assertSame(0, $this->eventRows());
    }

    public function testAnAgentIsRefusedEvenThoughTheActorPortCanDescribeOne(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->apply(
            self::STORE,
            self::ORDER_A,
            VerificationAction::Verified,
            $this->actor(InitiatorType::Agent, 99),
            0,
        );
    }

    // ---------------------------------------------------------------- concurrency

    public function testRepeatingADecisionIsNotAnError(): void
    {
        $first = $this->service->apply(self::STORE, self::ORDER_A, VerificationAction::Verified, $this->admin(), 0);
        $second = $this->service->apply(
            self::STORE,
            self::ORDER_A,
            VerificationAction::Verified,
            $this->operation(),
            $first->version,
        );

        self::assertTrue($second->isVerified);
        self::assertSame($first->version + 1, $second->version, 'the version advances even on a repeat');
        self::assertSame(2, $this->eventRows(), 'and both people are recorded as having said so');
    }

    public function testAStaleScreenCannotUndoSomebodyElsesDecision(): void
    {
        // Two people open the same order. Both are looking at version 0.
        $stale = $this->service->current(self::STORE, self::ORDER_A)->version;

        $this->service->apply(self::STORE, self::ORDER_A, VerificationAction::Verified, $this->admin(), $stale);

        try {
            $this->service->apply(
                self::STORE,
                self::ORDER_A,
                VerificationAction::Unverified,
                $this->operation(),
                $stale,
            );
            self::fail('A write carrying a stale version should have been refused.');
        } catch (VerificationConflict $conflict) {
            // The refusal carries what is actually true, so the screen can catch up.
            self::assertTrue($conflict->current->isVerified);
            self::assertSame(1, $conflict->current->version);
        }

        self::assertTrue($this->service->current(self::STORE, self::ORDER_A)->isVerified);
        self::assertSame(1, $this->eventRows(), 'the refused write left no trace');
    }

    public function testAWriterWithNoVersionAtAllIsStillAllowedToAct(): void
    {
        $this->service->apply(self::STORE, self::ORDER_A, VerificationAction::Verified, $this->admin(), null);

        self::assertTrue($this->service->current(self::STORE, self::ORDER_A)->isVerified);
    }

    // ---------------------------------------------------------------- what the page reads

    public function testThePageReaderAnswersForTheOrdersItWasAskedAbout(): void
    {
        $this->service->apply(self::STORE, self::ORDER_A, VerificationAction::Verified, $this->admin(), 0);

        $seen = $this->reader->forOrders(self::STORE, [(string) self::ORDER_A, (string) self::ORDER_B, 'x']);

        self::assertArrayHasKey((string) self::ORDER_A, $seen);
        self::assertTrue($seen[(string) self::ORDER_A]->isVerified);
        self::assertSame(1, $seen[(string) self::ORDER_A]->version);
        self::assertNotNull($seen[(string) self::ORDER_A]->verifiedLine);

        // Absent rather than present-and-false: no row means unverified, and the template says so
        // without needing one.
        self::assertArrayNotHasKey((string) self::ORDER_B, $seen);
        self::assertArrayNotHasKey('x', $seen, 'a non-numeric order id is skipped before any cast');
    }

    // ---------------------------------------------------------------- helpers

    private function admin(): TestActorInterface
    {
        return $this->actor(InitiatorType::Admin, self::ADMIN_ID);
    }

    private function operation(): TestActorInterface
    {
        return $this->actor(InitiatorType::Operation, self::OPERATION_ID);
    }

    private function actor(InitiatorType $type, int $id): TestActorInterface
    {
        return new class ($type, $id) implements TestActorInterface {
            public function __construct(private InitiatorType $type, private int $id) {}

            public function initiatorType(): InitiatorType
            {
                return $this->type;
            }

            public function initiatorId(): int
            {
                return $this->id;
            }

            public function displayName(): string
            {
                return 'Fixture';
            }
        };
    }

    private function stateRows(): int
    {
        return $this->countIn('{{%order_testing_order_verifications}}');
    }

    private function eventRows(): int
    {
        return $this->countIn('{{%order_testing_verification_events}}');
    }

    private function countIn(string $table): int
    {
        return (int) (new Query($this->connection))
            ->from($table)
            ->where(['store_source_id' => [self::STORE, self::OTHER_STORE]])
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

    private function cleanup(): void
    {
        foreach ([self::STORE, self::OTHER_STORE] as $store) {
            foreach ([
                '{{%order_testing_verification_events}}',
                '{{%order_testing_order_verifications}}',
                '{{%order58_orders}}',
            ] as $table) {
                $column = $table === '{{%order58_orders}}' ? 'account_id' : 'store_source_id';

                $this->connection->createCommand()->delete($table, [$column => $store])->execute();
            }
        }
    }
}
