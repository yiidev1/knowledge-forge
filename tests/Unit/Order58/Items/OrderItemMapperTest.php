<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Items;

use App\Order58\Application\Items\OrderItemMapper;
use App\Order58\Domain\Items\OrderItemNotMappable;
use Codeception\Test\Unit;

use function json_decode;

/**
 * Mapping one provider item: preserve everything, interpret nothing in place.
 *
 * ## The two halves
 *
 * The **columns** must come back exactly as the provider sent them — `[F7]` still on the name, `0`
 * still in `account_id`, five decimals still on the price, `data` still a JSON-encoded string. That is
 * the requirement this table exists to meet.
 *
 * **`comparison_json`** is the only place anything is reinterpreted: the prefix comes off, `data` is
 * decoded, and modifiers are flattened. Keeping those apart is what lets the comparison rules change
 * later without re-fetching a single order.
 */
final class OrderItemMapperTest extends Unit
{
    private const STORE = 1731;
    private const ORDER = 16800401;

    /** The client's verbatim sample, with both modifier shapes present. */
    private const DATA = '{"product":{"id":506941,"name":"Fried Chicken Wings (4)","tag":"","sn":"F7",'
        . '"cost_price":"10.35","price":"10.35","local_n":"0"},'
        . '"additionals":[{"additional_id":2711,"option_id":22821,"additional_name":"Add / Extra",'
        . '"option_name":"Add broccoli","extras":"1","qty":1,"price":"1.00","unit_price":1,'
        . '"target":"Fried Rice"}],'
        . '"combinations":{"id":418841,"name":null,"options":[{"attribute_id":3931,"option_id":23461,'
        . '"attribute_name":"Combination With","option_name":"Shrimp FR","price":null}],'
        . '"print_option":true}}';

    public function testEveryProviderValueIsPreservedExactly(): void
    {
        $item = (new OrderItemMapper())->toItem(self::STORE, self::ORDER, $this->sample(), 0);

        // The name keeps its bracketed code.
        $this->assertSame('[F7]Fried Chicken Wings (4)', $item->name);

        // The two that are NOT the store, and must stay zero.
        $this->assertSame(0, $item->accountId);
        $this->assertSame(0, $item->userId);
        $this->assertSame(self::STORE, $item->storeAccountId);

        // Money and quantity stay strings, five and three decimals intact.
        $this->assertSame('11.35000', $item->price);
        $this->assertSame('11.35000', $item->costPrice);
        $this->assertSame('1', $item->qty);
        $this->assertSame('0', $item->freeQty);

        $this->assertSame(45308981, $item->sourceItemId);
        $this->assertNull($item->desc);
        $this->assertNull($item->type);
        $this->assertNull($item->tag);
        $this->assertSame('ADDED', $item->flag);
        $this->assertSame(1, $item->packIdx);
        $this->assertSame(0, $item->printIdx);
        $this->assertSame(0, $item->deleted);
        $this->assertSame(4464241, $item->customerId);
        $this->assertSame(506941, $item->productId);

        // `data` is stored as the string it arrived as, byte for byte.
        $this->assertSame(self::DATA, $item->data);
    }

    /** Zero is a real provider value, not "absent" — treating it as null would rewrite the data. */
    public function testZeroSurvivesWhereZeroIsMeaningful(): void
    {
        $item = (new OrderItemMapper())->toItem(self::STORE, self::ORDER, $this->sample([
            'account_id' => '0', 'user_id' => '0', 'deleted' => '0', 'print_idx' => '0', 'product_id' => '0',
        ]), 0);

        $this->assertSame(0, $item->accountId);
        $this->assertSame(0, $item->userId);
        $this->assertSame(0, $item->deleted);
        $this->assertSame(0, $item->printIdx);
        $this->assertSame(0, $item->productId);
    }

    // ------------------------------------------------------------------ comparison_json

    public function testTheCanonicalShapeStripsThePrefixWithoutTouchingTheColumn(): void
    {
        $item = (new OrderItemMapper())->toItem(self::STORE, self::ORDER, $this->sample(), 0);
        $c = json_decode($item->comparisonJson, true);

        // The un-prefixed name, which is what the demo side also carries.
        $this->assertSame('Fried Chicken Wings (4)', $c['productName']);
        $this->assertSame('fried chicken wings (4)', $c['matchKey']);
        $this->assertSame('F7', $c['productSn']);
        // …while the display name, and the column, keep the prefix.
        $this->assertSame('[F7]Fried Chicken Wings (4)', $c['displayName']);
        $this->assertSame('[F7]Fried Chicken Wings (4)', $item->name);
    }

    /** With no `data.product.name` the prefix still has to come off the display name. */
    public function testThePrefixIsStrippedEvenWithoutNestedProductData(): void
    {
        $item = (new OrderItemMapper())->toItem(self::STORE, self::ORDER, $this->sample([
            'name' => '[A04]Spring Roll', 'data' => null,
        ]), 0);

        $c = json_decode($item->comparisonJson, true);
        $this->assertSame('Spring Roll', $c['productName']);
        $this->assertSame('[A04]Spring Roll', $item->name);
    }

    public function testAdditionalsAreFlattenedWithTheirQuantityAndPrice(): void
    {
        $item = (new OrderItemMapper())->toItem(self::STORE, self::ORDER, $this->sample(), 0);
        $c = json_decode($item->comparisonJson, true);

        $this->assertCount(1, $c['additionals']);
        $this->assertSame('Add / Extra', $c['additionals'][0]['name']);
        $this->assertSame('Add broccoli', $c['additionals'][0]['optionName']);
        $this->assertSame('1', $c['additionals'][0]['qty']);
        $this->assertSame('1.00', $c['additionals'][0]['unitPrice']);
        $this->assertSame('Fried Rice', $c['additionals'][0]['target']);
    }

    /** `combinations` is an object with an `options` list, not a list itself. */
    public function testCombinationsAreReadThroughTheirOptionsList(): void
    {
        $item = (new OrderItemMapper())->toItem(self::STORE, self::ORDER, $this->sample(), 0);
        $c = json_decode($item->comparisonJson, true);

        $this->assertCount(1, $c['combinations']);
        $this->assertSame('Combination With', $c['combinations'][0]['attributeName']);
        $this->assertSame('Shrimp FR', $c['combinations'][0]['optionName']);
    }

    /** `null` modifiers are the common case and mean "none", not "unreadable". */
    public function testNullModifiersBecomeEmptyLists(): void
    {
        $item = (new OrderItemMapper())->toItem(self::STORE, self::ORDER, $this->sample([
            'data' => '{"product":{"name":"Can Ginger Ale","sn":""},"additionals":null,"combinations":null}',
        ]), 0);

        $c = json_decode($item->comparisonJson, true);
        $this->assertSame([], $c['additionals']);
        $this->assertSame([], $c['combinations']);
        $this->assertSame('Can Ginger Ale', $c['productName']);
    }

    /** Unreadable `data` costs the modifiers, never the line — the column still has it verbatim. */
    public function testUnreadableDataStillProducesARow(): void
    {
        $item = (new OrderItemMapper())->toItem(self::STORE, self::ORDER, $this->sample([
            'data' => '{"product":{"name":"Broken",',
        ]), 0);

        $this->assertSame('{"product":{"name":"Broken",', $item->data);
        $c = json_decode($item->comparisonJson, true);
        $this->assertSame([], $c['additionals']);
        // Falls back to the display name with its prefix stripped.
        $this->assertSame('Fried Chicken Wings (4)', $c['productName']);
    }

    // ------------------------------------------------------------------ identity

    public function testAnItemWithoutAUsableIdIsRefused(): void
    {
        $this->expectException(OrderItemNotMappable::class);
        $this->expectExceptionMessageMatches('/without a usable "id"/');

        (new OrderItemMapper())->toItem(self::STORE, self::ORDER, $this->sample(['id' => null]), 0);
    }

    /**
     * The one that protects the table: a response describing another order.
     *
     * Writing it under the requested order's key would attribute one order's lines to another, and the
     * provider is the only party who could make that mistake.
     */
    public function testAnItemClaimingAnotherOrderIsRefused(): void
    {
        $this->expectException(OrderItemNotMappable::class);
        $this->expectExceptionMessageMatches('/claims order 99999999, but order 16800401 was requested/');

        (new OrderItemMapper())->toItem(self::STORE, self::ORDER, $this->sample(['order_id' => '99999999']), 0);
    }

    /** The hash moves when anything moves — including a field with no column of its own. */
    public function testTheContentHashCoversFieldsWithoutColumns(): void
    {
        $mapper = new OrderItemMapper();
        $base = $mapper->toItem(self::STORE, self::ORDER, $this->sample(), 0);
        $same = $mapper->toItem(self::STORE, self::ORDER, $this->sample(), 0);
        $extra = $mapper->toItem(self::STORE, self::ORDER, $this->sample(['some_future_field' => 'new']), 0);

        $this->assertSame($base->contentHash, $same->contentHash);
        $this->assertNotSame($base->contentHash, $extra->contentHash);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function sample(array $overrides = []): array
    {
        return $overrides + [
            'id' => '45308981',
            'name' => '[F7]Fried Chicken Wings (4)',
            'qty' => '1',
            'desc' => null,
            'price' => '11.35000',
            'cost_price' => '11.35000',
            'type' => null,
            'account_id' => '0',
            'user_id' => '0',
            'order_id' => '16800401',
            'data' => self::DATA,
            'customer_id' => '4464241',
            'product_id' => '506941',
            'tag' => null,
            'pack_idx' => '1',
            'flag' => 'ADDED',
            'print_idx' => '0',
            'deleted' => '0',
            'free_qty' => '0',
        ];
    }
}
