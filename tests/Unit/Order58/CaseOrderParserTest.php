<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\CaseOrders\CaseOrderParser;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The Case Order response, turned into orders — and everything that can go wrong with it.
 *
 * ## Why this is the test that matters most
 *
 * The parser is the one place the provider's shape is allowed to be anybody's problem. Ids arrive as
 * strings on an order and as integers on its lines, prices as strings, timestamps as numeric
 * strings, and nested objects are sometimes null and sometimes absent. Everything downstream — the
 * importer, the pages, the comparison that scores a trainee — depends on this file absorbing all of
 * it. When the provider changes something, this is where it should fail, loudly, and nowhere else.
 */
final class CaseOrderParserTest extends TestCase
{
    private CaseOrderParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CaseOrderParser();
    }

    /** @return mixed the sanitized sample the client supplied, decoded */
    private function sample(): mixed
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../../_data/order58/case-orders-sample.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public function testItReadsEveryOrderInTheResponse(): void
    {
        $result = $this->parser->parse($this->sample());

        self::assertTrue($result->isReadable());
        self::assertSame(2, $result->orderCount());
        self::assertSame([], $result->skipped);
    }

    /**
     * The store comes from the ORDER's `account_id`, never from a line's.
     *
     * `items[].account_id` is `0` in the provider's own sample. Taking it would file every order
     * under a store that does not exist — and both of these orders belong to different stores, which
     * is what makes a single global response able to serve every store at once.
     */
    public function testTheStoreComesFromTheOrderNotTheLine(): void
    {
        $orders = $this->parser->parse($this->sample())->orders;

        self::assertSame(1121, $orders[0]->storeSourceId());
        self::assertSame(431, $orders[1]->storeSourceId());
        self::assertSame(17066031, $orders[0]->sourceOrderId());
    }

    /** Items arrive with the order, which is the whole reason this endpoint replaces two. */
    public function testItemsArriveWithTheirOrder(): void
    {
        $orders = $this->parser->parse($this->sample())->orders;

        self::assertSame(5, $orders[0]->itemCount());
        self::assertSame(1, $orders[1]->itemCount());
        self::assertSame(6, $this->parser->parse($this->sample())->itemCount());
    }

    /**
     * **The same product twice, configured differently, stays two lines.**
     *
     * This order has two Steak & Cheese at different prices with different comments. Merging them by
     * product id would turn two things the customer asked for into one, and a trainee who entered
     * both would be scored against an order that never existed.
     */
    public function testTheSameProductTwiceIsNotMerged(): void
    {
        $items = $this->parser->parse($this->sample())->orders[0]->items;

        self::assertSame(45934271, $items[0]->sourceItemId);
        self::assertSame(45934281, $items[1]->sourceItemId);
        self::assertSame(317251, $items[0]->productId);
        self::assertSame(317251, $items[1]->productId, 'the same product');
        self::assertNotSame($items[0]->price, $items[1]->price, 'at different prices');
        self::assertNotSame($items[0]->packIdx, $items[1]->packIdx, 'in different packs');
    }

    /**
     * The line's configuration survives whole, in the column AND in the comparison.
     *
     * Both halves are asserted because they are two different promises. `data` is the faithful copy
     * of what the provider said, which is what makes a disputed order answerable later. `comparison_json`
     * is the interpretation the trainee's work is actually scored against — and it is built by the
     * canonicaliser the demo side uses, so a modifier that fails to reach it is a modifier the
     * comparison will report as missing from the customer's order.
     */
    public function testTheConfigurationSurvivesWhole(): void
    {
        $first = $this->parser->parse($this->sample())->orders[0]->items[0];

        self::assertNotNull($first->data, 'the provider\'s own configuration, kept verbatim');
        self::assertStringContainsString('Provolone Cheese', $first->data);
        self::assertStringContainsString('Half', $first->data, 'the size combination');
        self::assertStringContainsString('more mayo', $first->data, 'the line comment');

        self::assertStringContainsString(
            'Provolone Cheese',
            $first->comparisonJson,
            'the additional has to reach what the comparison scores',
        );
        self::assertStringContainsString('Half', $first->comparisonJson, 'and so does the combination');
    }

    /**
     * The nested `data` object is stored as the string this table already holds.
     *
     * The endpoint this replaces sent `data` as a JSON-encoded string; this one sends an object. The
     * column, the canonicaliser's input and `content_hash` all existed before today, so the object is
     * encoded rather than the table being taught a second shape — otherwise rows written by the two
     * integrations would hash differently while describing the same order.
     */
    public function testTheNestedConfigurationIsStoredAsAString(): void
    {
        $first = $this->parser->parse($this->sample())->orders[0]->items[0];

        self::assertIsString($first->data);
        self::assertSame('{', $first->data[0], 'a JSON document, not an array cast to a string');
        self::assertNotSame('', $first->contentHash, 'and it is hashed like every other row');
    }

    /**
     * **No payment secret survives, at any depth.**
     *
     * The live documents carry `card_num`, `exp_date` and a CVV — at the top of `data` and again
     * inside `data.cc`. Nothing here may reach the database. Asserted on the stored payload, which
     * is the thing that actually gets written.
     */
    public function testNoPaymentSecretReachesTheStoredPayload(): void
    {
        $order = $this->parser->parse($this->sample())->orders[0];

        foreach (['cvv', 'card_num', 'exp_date'] as $secret) {
            self::assertStringNotContainsString(
                '"' . $secret . '":"',
                $order->order->payloadJson,
                $secret . ' must not be stored',
            );
        }

        self::assertStringNotContainsString('PLACEHOLDER-NOT-REAL', $order->order->payloadJson);
        // And the order itself is still there, so redaction removed the secrets and nothing else.
        self::assertStringContainsString('reservation', $order->order->payloadJson);
    }

    /** Prices stay strings: they are money, and a float would not survive the round trip exactly. */
    public function testMoneyIsNeverTurnedIntoAFloat(): void
    {
        $order = $this->parser->parse($this->sample())->orders[0];

        self::assertSame('36.63000', $order->order->money['total_amount']);
        self::assertSame('12.28000', $order->items[0]->price);
    }

    /** The customer's number is read from the nested reservation, where the provider puts it. */
    public function testThePhoneComesFromTheReservation(): void
    {
        self::assertSame(
            '15550000000',
            $this->parser->parse($this->sample())->orders[0]->order->reservationPhone,
        );
    }

    /** Timestamps arrive as numeric strings and become integers. */
    public function testTimestampsBecomeIntegers(): void
    {
        self::assertSame(
            1792708938,
            $this->parser->parse($this->sample())->orders[0]->order->sourceTimes['source_created_at'],
        );
    }

    /** A real customer order is not a test order, however the flag is spelled. */
    public function testRealOrdersAreNotMarkedAsTests(): void
    {
        self::assertFalse($this->parser->parse($this->sample())->orders[0]->order->isTest);
    }

    /** An envelope is tolerated, because that is the commonest way an API grows one. */
    public function testAWrappedResponseIsStillRead(): void
    {
        $result = $this->parser->parse(['data' => $this->sample()]);

        self::assertSame(2, $result->orderCount());
    }

    /** A response that is not a list of orders fails as a whole, and says so. */
    public function testAnUnreadableResponseIsReportedRatherThanThrown(): void
    {
        $result = $this->parser->parse('not json at all');

        self::assertFalse($result->isReadable());
        self::assertNotNull($result->fatal);
        self::assertSame([], $result->orders);
    }

    /**
     * One bad order does not cost the other 199.
     *
     * There is one request a day. Discarding a whole response because one entry is malformed would
     * mean waiting until tomorrow to import the orders that were perfectly fine.
     */
    public function testOneBadOrderIsSkippedAndTheRestAreKept(): void
    {
        $sample = $this->sample();
        $sample[] = ['seq' => 'no id at all'];
        $sample[] = ['id' => '999', 'account_id' => null];

        $result = $this->parser->parse($sample);

        self::assertSame(2, $result->orderCount(), 'the good orders still import');
        self::assertCount(2, $result->skipped);
    }

    /**
     * A line claiming to belong to another order refuses the ORDER, not just the line.
     *
     * Filing it under the order it arrived inside would move a product onto somebody else's order,
     * which would score a trainee against something the customer never asked for, invisibly. But
     * dropping just that line is not the safe alternative: an order's lines are written by replacing
     * the whole set, so importing the other five would delete nothing visible and quietly leave the
     * order one line short of what the provider actually described.
     *
     * So the order is skipped and reported. Its previous import stays exactly as it was, and the
     * response's other orders are unaffected.
     */
    public function testALineNamingAnotherOrderFailsItsWholeOrder(): void
    {
        $sample = $this->sample();
        $sample[0]['items'][] = [
            'id' => 999_111, 'name' => 'Belongs elsewhere', 'qty' => 1, 'order_id' => 12_345_678,
        ];

        $result = $this->parser->parse($sample);

        self::assertSame(1, $result->orderCount(), 'the order with the stray line is not imported');
        self::assertSame(17_055_781, $result->orders[0]->sourceOrderId(), 'the other order still is');

        self::assertCount(1, $result->skipped);
        self::assertStringContainsString('17066031', $result->skipped[0], 'which order was skipped');
        self::assertStringContainsString('12345678', $result->skipped[0], 'and what it claimed');
    }

    /**
     * A line that cannot be read at all fails its order too, for the same reason.
     *
     * Not the response: the other orders are unaffected and import normally.
     */
    public function testAnUnreadableLineFailsOnlyItsOwnOrder(): void
    {
        $sample = $this->sample();
        $sample[0]['items'][] = ['name' => 'No id at all', 'qty' => 1];

        $result = $this->parser->parse($sample);

        self::assertSame(1, $result->orderCount());
        self::assertSame(17_055_781, $result->orders[0]->sourceOrderId());
        self::assertStringContainsString('17066031', $result->skipped[0]);
    }

    /** An order with no lines is legitimate, not malformed. */
    public function testAnOrderWithNoItemsIsStillAnOrder(): void
    {
        foreach ([null, [], 'nonsense'] as $items) {
            $sample = $this->sample();
            $sample[0]['items'] = $items;

            $result = $this->parser->parse($sample);

            self::assertSame(2, $result->orderCount());
            self::assertSame(0, $result->orders[0]->itemCount());
        }
    }
}
