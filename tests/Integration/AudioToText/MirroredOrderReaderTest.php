<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Infrastructure\DbMirroredOrderReader;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The Seq lookup behind the Manage Orders table, against real MySQL.
 *
 * Against the real database because the rule being tested **is** a database rule:
 * `ux_order58_orders_account_order` is unique on `(account_id, source_order_id)` and on neither column
 * alone, so "the same order number under two stores" is a legal state here. The whole reason this
 * reader takes an account id is that one merchant's order number must never print another merchant's
 * Seq — and only a database that permits the collision can prove it does not.
 *
 * Fixture ids sit in the `9876559xx` range, well outside anything mirrored, and every row is removed
 * again.
 */
final class MirroredOrderReaderTest extends Unit
{
    private const STORE_A = 987655840;
    private const STORE_B = 987655841;

    /** Deliberately the SAME number under both stores — the collision the account bound exists for. */
    private const SHARED_ORDER = 16800777;

    private const A_ONLY_ORDER = 16800778;
    private const NO_SEQ_ORDER = 16800779;

    /** Nineteen digits: what a BIGINT UNSIGNED holds, and past what an int could carry on 32-bit. */
    private const HUGE_ORDER = '9223372036854775807';

    private ConnectionInterface $connection;
    private DbMirroredOrderReader $reader;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->reader = new DbMirroredOrderReader($this->connection);

        $this->seed(self::STORE_A, self::SHARED_ORDER, '31#1');
        $this->seed(self::STORE_B, self::SHARED_ORDER, '99#9');
        $this->seed(self::STORE_A, self::A_ONLY_ORDER, '32#1');
        $this->seed(self::STORE_A, self::NO_SEQ_ORDER, null);
        $this->seed(self::STORE_A, (int) self::HUGE_ORDER, '33#1');
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    /** The one that matters: the same order number, two stores, two different answers. */
    public function testTheSameOrderNumberUnderTwoStoresNeverCrosses(): void
    {
        $forA = $this->reader->forOrders(self::STORE_A, [(string) self::SHARED_ORDER]);
        $forB = $this->reader->forOrders(self::STORE_B, [(string) self::SHARED_ORDER]);

        $this->assertSame('31#1', $forA[(string) self::SHARED_ORDER]->seq);
        $this->assertSame('99#9', $forB[(string) self::SHARED_ORDER]->seq);
    }

    /** An order that is another store's and not this one's is simply absent, not blank. */
    public function testAnotherStoresOrderIsNotReported(): void
    {
        $found = $this->reader->forOrders(self::STORE_B, [(string) self::A_ONLY_ORDER]);

        $this->assertArrayNotHasKey((string) self::A_ONLY_ORDER, $found);
    }

    /** No mirrored row at all — the page shows a dash and offers no receipt. */
    public function testAnOrderNobodyMirroredIsAbsent(): void
    {
        $found = $this->reader->forOrders(self::STORE_A, ['16899999']);

        $this->assertSame([], $found);
    }

    /**
     * "Mirrored but with no Seq" is a different answer from "not mirrored".
     *
     * The column is nullable, and the two cells read the difference differently: Seq shows a dash
     * either way, but the receipt link is offered only for the first — the order exists, so there is
     * a verified id to ask the provider about.
     */
    public function testAMirroredOrderWithNoSeqIsStillPresent(): void
    {
        $found = $this->reader->forOrders(self::STORE_A, [(string) self::NO_SEQ_ORDER]);

        $this->assertArrayHasKey((string) self::NO_SEQ_ORDER, $found);
        $this->assertNull($found[(string) self::NO_SEQ_ORDER]->seq);
        $this->assertSame('—', $found[(string) self::NO_SEQ_ORDER]->seqLabel());
    }

    public function testTheLargestOrderIdAMirrorCanHoldIsMatched(): void
    {
        $found = $this->reader->forOrders(self::STORE_A, [self::HUGE_ORDER]);

        $this->assertSame('33#1', $found[self::HUGE_ORDER]->seq);
    }

    /**
     * One row in, at most one row out.
     *
     * The unique key makes this true today; asserting it is what would catch a future join that
     * fanned a page row out into several. A duplicated key is impossible in a PHP map, so the count
     * of the whole answer is what is checked.
     */
    public function testAPageOfOrdersProducesOneAnswerEach(): void
    {
        $asked = [
            (string) self::SHARED_ORDER,
            (string) self::A_ONLY_ORDER,
            (string) self::NO_SEQ_ORDER,
            '16899999',
        ];

        $found = $this->reader->forOrders(self::STORE_A, $asked);

        // Three of the four exist; the fourth is absent rather than present-and-empty.
        $this->assertCount(3, $found);
    }

    /**
     * An order id is free text at upload, so most of what arrives is not an order id.
     *
     * None of these may reach the database, and none may appear in the answer.
     */
    public function testNonNumericAndOversizedIdsAreAnsweredWithoutAQuery(): void
    {
        $found = $this->reader->forOrders(self::STORE_A, [
            '',
            'ORDER-123',
            '12 34',
            '-5',
            // Twenty digits: one more than a BIGINT UNSIGNED holds, so a cast would silently clamp it
            // and match the wrong row.
            '99999999999999999999',
        ]);

        $this->assertSame([], $found);
    }

    /** An empty page asks nothing at all. */
    public function testNoOrdersMeansNoQuery(): void
    {
        $this->assertSame([], $this->reader->forOrders(self::STORE_A, []));
    }

    private function seed(int $accountId, int $sourceOrderId, ?string $seq): void
    {
        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $accountId,
            'source_order_id' => $sourceOrderId,
            'seq' => $seq,
            'content_hash' => str_repeat('a', 64),
            'payload_json' => '{}',
            'synced_at' => '2026-10-21 00:00:00',
            'created_at' => '2026-10-21 00:00:00',
            'updated_at' => '2026-10-21 00:00:00',
        ])->execute();
    }

    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => [self::STORE_A, self::STORE_B]])
            ->execute();
    }
}
