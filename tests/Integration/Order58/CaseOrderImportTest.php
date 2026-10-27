<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Application\CaseOrders\CaseOrderImporter;
use App\Order58\Application\CaseOrders\CaseOrderParser;
use App\Order58\Infrastructure\DbItemSyncStateRepository;
use App\Order58\Infrastructure\DbOrder58OrderItemRepository;
use App\Order58\Infrastructure\DbOrder58OrderRepository;
use App\Shared\Infrastructure\Db\DatabaseTarget;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_values;
use function file_get_contents;
use function json_decode;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * The Case Order import, against real MySQL. **No test here calls the Order58 API.**
 *
 * Every row is written under a fixture store in the `9876559xx` range, so nothing a real store owns is
 * read, written or deleted. The orders keep their sample order numbers: the identity is
 * `(account_id, source_order_id)`, so a fixture store with a real order number cannot collide with a
 * real row — and keeping the numbers means the fixture stays a faithful copy of the response.
 *
 * ## Why these four properties and not others
 *
 * The provider allows **one request a day**. That single fact is what makes each of these a property
 * worth a test rather than a reasonable expectation:
 *
 * - **Idempotency** — re-running the import must be free. If it were not, the only way to recover from
 *   a half-finished run would be to wait until tomorrow.
 * - **Absence is not deletion** — the endpoint returns a rolling window. An order that has aged out of
 *   it must not be deleted, or the practice catalogue would quietly shrink every day.
 * - **Replacement inside an order** — a line the customer had removed is simply absent from the
 *   response, so an order's lines are replaced rather than merged, or a removed line would live
 *   forever.
 * - **`comparison_json` is populated** — this is the column a trainee's work is actually scored
 *   against. Rows could import perfectly and still be useless if it were empty, and nothing on screen
 *   would say why every comparison reported everything missing.
 */
final class CaseOrderImportTest extends Unit
{
    /** Fixture stores. Nothing outside this range is touched. */
    private const STORE_A = 987655941;
    private const STORE_B = 987655942;

    private const ORDER_A = 17066031;
    private const ORDER_B = 17055781;

    private ConnectionInterface $connection;
    private CaseOrderParser $parser;
    private CaseOrderImporter $importer;
    private bool $ready = false;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        DatabaseTarget::resolve($this->connection)->assertLocalForSchemaChange();

        $this->ready = true;

        $this->parser = new CaseOrderParser();
        $this->importer = new CaseOrderImporter(
            new DbOrder58OrderRepository($this->connection),
            new DbOrder58OrderItemRepository($this->connection),
            new DbItemSyncStateRepository($this->connection),
            new MutableClock('2026-10-26 09:00:00'),
            new NullLogger(),
        );

        $this->cleanup();
    }

    protected function _after(): void
    {
        if ($this->ready) {
            $this->cleanup();
        }
    }

    // ------------------------------------------------------------------- the orders and their lines

    /** Both orders, both line sets, filed under the store the ORDER named. */
    public function testItWritesEveryOrderAndItsLines(): void
    {
        $report = $this->importer->import($this->parser->parse($this->payload()));

        self::assertSame(2, $report->received);
        self::assertSame(2, $report->inserted);
        self::assertSame(0, $report->updated);
        self::assertSame(6, $report->itemsImported, '5 lines on one order, 1 on the other');
        self::assertSame([], $report->problems);

        self::assertSame(5, $this->itemCount(self::STORE_A, self::ORDER_A));
        self::assertSame(1, $this->itemCount(self::STORE_B, self::ORDER_B));

        /*
         * The store is the ORDER's `account_id`, never the line's.
         *
         * Every line in the sample carries `account_id: 0`. Had that been taken as the store, all six
         * rows would sit under a store that does not exist — and the practice page, which selects by
         * store, would show an order with no lines at all.
         */
        self::assertSame(0, $this->itemCount(0, self::ORDER_A), 'nothing filed under the line account');
    }

    /**
     * Running it again changes nothing.
     *
     * Not "produces no error" — produces the **same rows**. Duplicate lines would be the dangerous
     * failure here, because the comparison counts them and would report a trainee as having missed
     * half an order they entered correctly.
     */
    public function testASecondImportIsFree(): void
    {
        $this->importer->import($this->parser->parse($this->payload()));
        $again = $this->importer->import($this->parser->parse($this->payload()));

        /*
         * Unchanged, not updated — and that distinction is the point.
         *
         * The importer compares the stored content hash before writing, so an order the provider
         * returned identically costs no upsert and no item replacement. Re-running is therefore free
         * in the literal sense: no rows are touched at all, so nothing that demo orders, attempts or
         * topics point at is churned.
         */
        self::assertSame(0, $again->inserted, 'nothing new');
        self::assertSame(0, $again->updated, 'and nothing rewritten');
        self::assertSame(2, $again->unchanged);
        self::assertSame([], $again->problems);

        self::assertSame(2, $this->orderCount());
        self::assertSame(5, $this->itemCount(self::STORE_A, self::ORDER_A));
        self::assertSame(1, $this->itemCount(self::STORE_B, self::ORDER_B));
    }

    /**
     * A changed order is UPDATED in place, never duplicated.
     *
     * With manual synchronisation now repeatable at will, this is the case that runs most often: the
     * same order comes back having moved on. `(account_id, source_order_id)` is the identity, so the
     * row has to be rewritten rather than added beside itself — a duplicate would split the demo
     * orders, attempts and topics that hang off that pair.
     */
    public function testAChangedOrderIsUpdatedInPlaceAndNeverDuplicated(): void
    {
        $this->importer->import($this->parser->parse($this->payload()));

        $changed = $this->payload();
        $changed[0]['status'] = 'COMPLETED';
        $changed[0]['total_amount'] = '99.99';

        $second = $this->importer->import($this->parser->parse($changed));

        self::assertSame(0, $second->inserted, 'nothing new was created');
        self::assertSame(1, $second->updated, 'the changed order was rewritten');
        self::assertSame(1, $second->unchanged, 'and the untouched one was left alone');
        self::assertSame([], $second->problems);

        // The identity held: still two orders, not three.
        self::assertSame(2, $this->orderCount());

        $stored = $this->connection->createQuery()
            ->select(['status', 'total_amount'])
            ->from('{{%order58_orders}}')
            ->where(['account_id' => self::STORE_A, 'source_order_id' => self::ORDER_A])
            ->one();

        self::assertIsArray($stored);
        self::assertSame('COMPLETED', $stored['status']);
        self::assertSame('99.99000', $stored['total_amount']);

        // The lines were replaced, not appended to.
        self::assertSame(5, $this->itemCount(self::STORE_A, self::ORDER_A));
    }

    /**
     * Repeating a synchronisation many times never grows the table.
     *
     * The requirement is that an administrator may press the button as often as they like; this is
     * what has to remain true when they do.
     */
    public function testRepeatedImportsNeverAccumulateRows(): void
    {
        for ($run = 0; $run < 5; ++$run) {
            $this->importer->import($this->parser->parse($this->payload()));
        }

        self::assertSame(2, $this->orderCount(), 'five runs, still two orders');
        self::assertSame(5, $this->itemCount(self::STORE_A, self::ORDER_A));
        self::assertSame(1, $this->itemCount(self::STORE_B, self::ORDER_B));
    }

    /**
     * An order missing from today's response is not deleted.
     *
     * The endpoint returns a rolling window of recent orders, so "absent" means "not in this answer",
     * never "gone". A sync that deleted what it did not see would empty the practice catalogue over
     * a few days, one window at a time.
     */
    public function testAnOrderAbsentFromTheResponseSurvives(): void
    {
        $both = $this->payload();

        $this->importer->import($this->parser->parse($both));

        // A later response that contains only the second order.
        $this->importer->import($this->parser->parse([$both[1]]));

        self::assertSame(2, $this->orderCount(), 'the absent order is still there');
        self::assertSame(5, $this->itemCount(self::STORE_A, self::ORDER_A), 'with its lines');
    }

    /**
     * Within an order that IS in the response, the line set is replaced.
     *
     * This is the other half of the rule above, and the two are easy to confuse. An order absent from
     * the response keeps everything; an order present in it is described completely by it, so a line
     * the customer removed has to disappear.
     */
    public function testALineRemovedFromAnOrderIsRemovedFromTheOrder(): void
    {
        $payload = $this->payload();
        $this->importer->import($this->parser->parse($payload));

        $removed = (int) $payload[0]['items'][4]['id'];
        unset($payload[0]['items'][4]);
        $payload[0]['items'] = array_values($payload[0]['items']);

        $this->importer->import($this->parser->parse($payload));

        self::assertSame(4, $this->itemCount(self::STORE_A, self::ORDER_A));
        self::assertSame(0, $this->lineCount(self::STORE_A, self::ORDER_A, $removed), 'and it is gone');
        self::assertSame(1, $this->itemCount(self::STORE_B, self::ORDER_B), 'the other order untouched');
    }

    // ------------------------------------------------------------------ what the comparison reads

    /**
     * The scored column is populated, and it carries the modifiers.
     *
     * `comparison_json` is built by the canonicaliser the demo side also uses, so this is the assertion
     * that the two halves of a comparison are speaking about the same thing. A row with an empty one
     * imports silently and then reports every item as missing.
     */
    public function testTheComparisonColumnsAreWritten(): void
    {
        $this->importer->import($this->parser->parse($this->payload()));

        $row = $this->connection->createQuery()
            ->select(['name', 'data', 'comparison_json', 'content_hash', 'position'])
            ->from('{{%order58_order_items}}')
            ->where([
                'store_account_id' => self::STORE_A,
                'source_order_id' => self::ORDER_A,
                'position' => 0,
            ])
            ->one();

        self::assertIsArray($row);
        self::assertSame('Steak & Cheese', $row['name'], 'the provider\'s own name, verbatim');

        self::assertIsString($row['comparison_json']);
        self::assertStringContainsString('Provolone Cheese', $row['comparison_json'], 'the additional');
        self::assertStringContainsString('Half', $row['comparison_json'], 'the combination');

        self::assertIsString($row['data'], 'the nested object, stored as the string this column holds');
        self::assertStringContainsString('more mayo', $row['data'], 'including the line comment');

        self::assertIsString($row['content_hash']);
        self::assertSame(64, strlen($row['content_hash']), 'sha256, as every other row carries');
    }

    /**
     * The order reports its items as present, not as never fetched.
     *
     * This is the assertion that stops a silent, total failure. `ItemAvailability::of()` reads
     * `items_status`, and `PENDING` — the column default — means "the items were never requested". An
     * order whose five lines are sitting in the table would still compare against nothing and report
     * every product as missing, with the trainee blamed for all of them and no sign on the page that
     * the original order was the thing that was wrong.
     */
    public function testTheOrderReportsItsItemsAsSynced(): void
    {
        $this->importer->import($this->parser->parse($this->payload()));

        $row = $this->connection->createQuery()
            ->select(['items_status', 'items_count', 'items_synced_at', 'items_response_hash', 'items_error'])
            ->from('{{%order58_orders}}')
            ->where(['account_id' => self::STORE_A, 'source_order_id' => self::ORDER_A])
            ->one();

        self::assertIsArray($row);
        self::assertSame('SYNCED', $row['items_status']);
        self::assertSame(5, (int) $row['items_count']);
        self::assertNotNull($row['items_synced_at'], 'and it says when');
        self::assertNull($row['items_error']);
        self::assertIsString($row['items_response_hash'], 'a change signal for the next run');
    }

    /**
     * An order with no lines is `EMPTY`, which is a successful answer rather than a failure.
     *
     * The distinction is the whole reason the status has two success values: `EMPTY` compares as "the
     * customer ordered nothing from this list", `PENDING` compares as "we do not know". Treating a
     * genuinely empty order as unknown would hide a real order behind a warning.
     */
    public function testAnOrderWithNoLinesIsRecordedAsEmpty(): void
    {
        $payload = $this->payload();
        $payload[1]['items'] = [];

        $this->importer->import($this->parser->parse($payload));

        $status = $this->connection->createQuery()
            ->select(['items_status'])
            ->from('{{%order58_orders}}')
            ->where(['account_id' => self::STORE_B, 'source_order_id' => self::ORDER_B])
            ->scalar();

        self::assertSame('EMPTY', $status);
    }

    // ---------------------------------------------------------------------------------- the fixture

    /**
     * The sanitized sample, re-homed onto fixture stores.
     *
     * @return list<array<array-key, mixed>>
     */
    private function payload(): array
    {
        // The same fixture the unit test parses, located the same way it locates it.
        $json = file_get_contents(__DIR__ . '/../../_data/order58/case-orders-sample.json');
        self::assertIsString($json);

        /** @var list<array<array-key, mixed>> $orders */
        $orders = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        $orders[0]['account_id'] = self::STORE_A;
        $orders[1]['account_id'] = self::STORE_B;

        return $orders;
    }

    private function orderCount(): int
    {
        return (int) $this->connection->createQuery()
            ->from('{{%order58_orders}}')
            ->where(['account_id' => [self::STORE_A, self::STORE_B]])
            ->count();
    }

    private function itemCount(int $store, int $order): int
    {
        return (int) $this->connection->createQuery()
            ->from('{{%order58_order_items}}')
            ->where(['store_account_id' => $store, 'source_order_id' => $order])
            ->count();
    }

    private function lineCount(int $store, int $order, int $sourceItemId): int
    {
        return (int) $this->connection->createQuery()
            ->from('{{%order58_order_items}}')
            ->where([
                'store_account_id' => $store,
                'source_order_id' => $order,
                'source_item_id' => $sourceItemId,
            ])
            ->count();
    }

    /** Fixture stores only. `ON DELETE CASCADE` takes the lines with the orders. */
    private function cleanup(): void
    {
        $stores = [self::STORE_A, self::STORE_B];

        $this->connection->createCommand()
            ->delete('{{%order58_order_items}}', ['store_account_id' => $stores])
            ->execute();

        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => $stores])
            ->execute();
    }
}
