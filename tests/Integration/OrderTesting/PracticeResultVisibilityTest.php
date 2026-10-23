<?php

declare(strict_types=1);

namespace App\Tests\Integration\OrderTesting;

use App\OrderTesting\Application\Practice\PracticeResultVisibility;
use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\TestActorInterface;
use App\OrderTesting\Infrastructure\DbDemoOrderRepository;
use App\OrderTesting\Infrastructure\DbTestAttemptRepository;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use App\Shared\Domain\Clock\ClockInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

use function gmdate;
use function sort;
use function str_pad;
use function str_repeat;

/**
 * Whose imported results a practice surface may show.
 *
 * ## The hole this closes
 *
 * The agent's demo-order count was narrowed first, but three pages still reached a demo order by the
 * id in their path — the comparison, the printed receipt and the **All Demo Orders** list — and none
 * of them asked whose work it was. An agent could read a colleague's comparison, and the whole of a
 * stranger's receipt (name, address, telephone number), by typing a number into the address bar; the
 * flat list handed them every practitioner's work on that order without even that much effort.
 *
 * Asserted against the real tables, because the question is a join over two of them and the realm
 * must travel with the id — `ADMIN 7101` and `AGENT 7101` are different people.
 *
 * Fixture ids sit in the `9876559xx` range and every row is removed again.
 */
final class PracticeResultVisibilityTest extends Unit
{
    private const STORE = 987655981;
    private const ORDER = 16800993;

    private const AGENT_A = 7101;
    private const AGENT_B = 7102;

    private ConnectionInterface $connection;
    private DbDemoOrderRepository $demoOrders;
    private DbTestAttemptRepository $attempts;

    private int $seq = 0;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->attempts = new DbTestAttemptRepository($this->connection);
        $this->demoOrders = new DbDemoOrderRepository($this->connection, new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('now', new DateTimeZone('UTC'));
            }
        });
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    /** The reader's own result, which is the only case that may answer yes. */
    public function testAnAgentOwnsTheResultOfTheirOwnAttempt(): void
    {
        $demoId = $this->seedDemo($this->seedAttempt('AGENT', self::AGENT_A));

        self::assertTrue($this->visibilityFor('AGENT', self::AGENT_A)->belongsToReader($this->demo($demoId)));
    }

    /** Another agent's result is not theirs, however much the two share a store and an order. */
    public function testAnAgentDoesNotOwnAnotherAgentsResult(): void
    {
        $demoId = $this->seedDemo($this->seedAttempt('AGENT', self::AGENT_B));

        self::assertFalse($this->visibilityFor('AGENT', self::AGENT_A)->belongsToReader($this->demo($demoId)));
    }

    /** Nor an administrator's. */
    public function testAnAgentDoesNotOwnAnAdministratorsResult(): void
    {
        $demoId = $this->seedDemo($this->seedAttempt('ADMIN', 42));

        self::assertFalse($this->visibilityFor('AGENT', self::AGENT_A)->belongsToReader($this->demo($demoId)));
    }

    /**
     * An Unmatched result belongs to nobody, and is therefore nobody's to read here.
     *
     * Not withheld because it is secret — withheld because claiming it would be an invention. The
     * management surfaces still list it, which is where somebody can go and look into it.
     */
    public function testNobodyOwnsAnUnmatchedResult(): void
    {
        $demoId = $this->seedDemo(null);

        self::assertFalse($this->visibilityFor('AGENT', self::AGENT_A)->belongsToReader($this->demo($demoId)));
        self::assertFalse($this->visibilityFor('ADMIN', 42)->belongsToReader($this->demo($demoId)));
    }

    /**
     * The realm is half the answer.
     *
     * The three realms number their users separately, so matching on the id alone would hand an agent
     * the record of whichever administrator happens to share the number.
     */
    public function testTheRealmIsComparedAndNotJustTheId(): void
    {
        $demoId = $this->seedDemo($this->seedAttempt('ADMIN', self::AGENT_A));

        self::assertTrue($this->visibilityFor('ADMIN', self::AGENT_A)->belongsToReader($this->demo($demoId)));
        self::assertFalse($this->visibilityFor('AGENT', self::AGENT_A)->belongsToReader($this->demo($demoId)));
    }

    /** The list narrowing keeps the reader's own rows and drops the rest, in one pass. */
    public function testTheListKeepsOnlyTheReadersOwnResults(): void
    {
        $mine = $this->seedAttempt('AGENT', self::AGENT_A);
        $theirs = $this->seedAttempt('AGENT', self::AGENT_B);

        $first = $this->seedDemo($mine);
        $this->seedDemo($theirs);
        $this->seedDemo(null);
        $second = $this->seedDemo($mine);

        $visible = $this->visibilityFor('AGENT', self::AGENT_A)
            ->onlyTheReaders($this->demoOrders->forSourceOrder(self::STORE, self::ORDER));

        self::assertSame([$first, $second], $this->idsOf($visible));
    }

    /** A page of nobody's work narrows to nothing rather than to everything. */
    public function testTheListIsEmptyWhenNoneOfItIsTheReaders(): void
    {
        $this->seedDemo($this->seedAttempt('AGENT', self::AGENT_B));
        $this->seedDemo(null);

        self::assertSame([], $this->visibilityFor('AGENT', self::AGENT_A)
            ->onlyTheReaders($this->demoOrders->forSourceOrder(self::STORE, self::ORDER)));
    }

    /** And an attempt that produced nothing produces nothing to read. */
    public function testAnAttemptWithNoResultShowsNothing(): void
    {
        $this->seedAttempt('AGENT', self::AGENT_A);

        self::assertSame([], $this->visibilityFor('AGENT', self::AGENT_A)
            ->onlyTheReaders($this->demoOrders->forSourceOrder(self::STORE, self::ORDER)));
    }

    /**
     * @param list<DemoOrder> $demos
     *
     * @return list<int>
     */
    private function idsOf(array $demos): array
    {
        $out = [];

        foreach ($demos as $demo) {
            $out[] = $demo->demoOrderId;
        }

        sort($out);

        return $out;
    }

    private function demo(int $demoOrderId): DemoOrder
    {
        $demo = $this->demoOrders->findOne(self::STORE, self::ORDER, $demoOrderId);

        self::assertNotNull($demo, 'the fixture row must be readable back');

        return $demo;
    }

    private function visibilityFor(string $realm, int $actorId): PracticeResultVisibility
    {
        return new PracticeResultVisibility(
            $this->attempts,
            new class ($realm, $actorId) implements TestActorInterface {
                public function __construct(private string $realm, private int $actorId) {}

                public function initiatorType(): InitiatorType
                {
                    return InitiatorType::from($this->realm);
                }

                public function initiatorId(): int
                {
                    return $this->actorId;
                }

                public function displayName(): string
                {
                    return 'Fixture reader';
                }
            },
        );
    }

    private function seedAttempt(string $realm, int $actorId): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order_testing_attempts}}', [
            'public_id' => str_pad((string) (++$this->seq), 32, 'c', STR_PAD_LEFT),
            'store_source_id' => self::STORE,
            'source_order_id' => self::ORDER,
            'initiated_by_type' => $realm,
            'initiated_by_id' => $actorId,
            'status' => 'EXPIRED',
            'started_at' => $now,
            'expires_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    /** @return int the demo order id */
    private function seedDemo(?int $attemptId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $demoOrderId = 16640000 + ++$this->seq;

        $this->connection->createCommand()->insert('{{%order_testing_demo_orders}}', [
            'public_id' => str_pad((string) $this->seq, 32, 'd', STR_PAD_LEFT),
            'store_source_id' => self::STORE,
            'source_order_id' => self::ORDER,
            'demo_order_id' => $demoOrderId,
            'matched_attempt_id' => $attemptId,
            'source_filename' => 'fixture.json',
            'content_hash' => str_repeat('1', 64),
            'raw_payload' => '{}',
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return $demoOrderId;
    }

    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%order_testing_demo_orders}}', ['store_source_id' => self::STORE])->execute();
        $this->connection->createCommand()
            ->delete('{{%order_testing_attempts}}', ['store_source_id' => self::STORE])->execute();
    }
}
