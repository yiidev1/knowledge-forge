<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\OrderTesting\Application\Comparison\OrderComparator;
use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Domain\Comparison\ComparisonStatus;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemAvailability;
use App\OrderTesting\Domain\Comparison\ItemComparison;
use App\OrderTesting\Domain\Comparison\NormalizedItem;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\OrderTesting\Domain\Comparison\OrderComparison;
use App\OrderTesting\Domain\Comparison\OriginalItems;
use App\Shared\Order58\Items\CanonicalItem;
use App\Shared\Order58\Items\ItemCanonicalizer;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function count;
use function json_encode;

/**
 * Comparing the original order's line items against the demo order's.
 *
 * ## The defect this exists for
 *
 * The original order's lines are not in its payload — that document has no line items at any depth,
 * which is why a second provider endpoint exists. Before this, an order whose items had never been
 * fetched looked exactly like an order with an empty basket, and every line the trainee had entered was
 * reported `ExtraInDemo`. The page told a trainee who had recreated the order perfectly that they had
 * invented all of it.
 *
 * So the first group of tests here is about the difference between "this order has no lines" and "we do
 * not know what its lines were", and only the provider saying `OrderItems: []` means the first.
 *
 * **Nothing here reaches a network or a database.** The items are handed in as a snapshot, which is what
 * the reader produces from local tables.
 */
final class OriginalItemComparisonTest extends TestCase
{
    private const WINGS = '[F7]Fried Chicken Wings (4)';

    // ------------------------------------------------------------------ availability

    /**
     * The defect, stated as a test.
     *
     * @dataProvider notComparableStates
     */
    public function testAnOriginalWhoseItemsAreUnknownNeverBlamesTheDemo(ItemAvailability $availability): void
    {
        $comparison = $this->compare(
            [$this->wings()],
            OriginalItems::of($availability, [$this->item($this->wings())]),
        );

        $this->assertCount(1, $comparison->items);
        $this->assertSame(
            ComparisonStatus::Uncomparable,
            $comparison->items[0]->status,
            'An unknown original must never make the demo line "extra".',
        );
        $this->assertNotSame(ComparisonStatus::ExtraInDemo, $comparison->items[0]->status);
        $this->assertFalse($comparison->itemsWereCompared());
        $this->assertNotSame('', $comparison->itemNote(), 'The page must say why.');
    }

    /** @return iterable<string, array{ItemAvailability}> */
    public static function notComparableStates(): iterable
    {
        yield 'never requested' => [ItemAvailability::NeverRequested];
        yield 'queued' => [ItemAvailability::Queued];
        yield 'fetching, nothing stored before' => [ItemAvailability::Fetching];
        yield 'failed, nothing stored before' => [ItemAvailability::Unavailable];
    }

    /** And the demo side stays completely visible, so nobody's work is hidden by a sync gap. */
    public function testTheDemoLinesRemainFullyVisibleWhenNotComparable(): void
    {
        $comparison = $this->compare([$this->wings(), $this->soup()], OriginalItems::neverRequested());

        $this->assertCount(2, $comparison->items);

        foreach ($comparison->items as $row) {
            $this->assertNotNull($row->demo);
            $this->assertNull($row->original);
            // Every field too, not just the row: a field that compared would be a false verdict.
            foreach ($row->fields as $field) {
                $this->assertSame(ComparisonStatus::Uncomparable, $field->status);
            }
        }
    }

    /** `EMPTY` is the one case where an empty original is a fact, so a demo line really is extra. */
    public function testAnAuthoritativelyEmptyOriginalDoesReportExtraLines(): void
    {
        $comparison = $this->compare(
            [$this->wings()],
            OriginalItems::of(ItemAvailability::AuthoritativeEmpty, []),
        );

        $this->assertTrue($comparison->itemsWereCompared());
        $this->assertSame(ComparisonStatus::ExtraInDemo, $comparison->items[0]->status);
    }

    /** Two empty sides that are both known is agreement, and says nothing about a sync. */
    public function testTwoKnownEmptyOrdersProduceNoRowsAndNoNote(): void
    {
        $comparison = $this->compare([], OriginalItems::of(ItemAvailability::AuthoritativeEmpty, []));

        $this->assertSame([], $comparison->items);
        $this->assertSame('', $comparison->itemNote());
        $this->assertTrue($comparison->itemsWereCompared());
    }

    /**
     * @dataProvider staleStates
     *
     * A kept snapshot is still the only item data there is. Discarding it because a later refresh
     * failed would display nothing in order to avoid displaying something slightly old.
     */
    public function testAKeptSnapshotIsStillComparedAndIsLabelledStale(ItemAvailability $availability): void
    {
        $comparison = $this->compare(
            [$this->wings()],
            OriginalItems::of($availability, [$this->item($this->wings())], '2026-10-08 09:00:00'),
        );

        $this->assertTrue($comparison->itemsWereCompared());
        $this->assertSame(ComparisonStatus::Match, $comparison->items[0]->status);
        $this->assertTrue($availability->isStale());
        $this->assertNotSame('', $comparison->itemNote());
        $this->assertSame('2026-10-08 09:00:00', $comparison->itemsSyncedAt);
    }

    /** @return iterable<string, array{ItemAvailability}> */
    public static function staleStates(): iterable
    {
        yield 'failed over a snapshot' => [ItemAvailability::StaleAfterFailure];
        yield 'refreshing over a snapshot' => [ItemAvailability::StaleWhileFetching];
    }

    /** The same status means different things depending on whether anything was ever stored. */
    public function testFailedIsOnlyUnavailableWhenNothingWasEverStored(): void
    {
        $this->assertSame(ItemAvailability::Unavailable, ItemAvailability::of('FAILED', false));
        $this->assertSame(ItemAvailability::StaleAfterFailure, ItemAvailability::of('FAILED', true));
        $this->assertFalse(ItemAvailability::of('FAILED', false)->isComparable());
        $this->assertTrue(ItemAvailability::of('FAILED', true)->isComparable());
    }

    public function testProcessingIsOnlyBlockingWhenNothingWasEverStored(): void
    {
        $this->assertSame(ItemAvailability::Fetching, ItemAvailability::of('PROCESSING', false));
        $this->assertSame(ItemAvailability::StaleWhileFetching, ItemAvailability::of('PROCESSING', true));
        $this->assertTrue(ItemAvailability::of('PROCESSING', true)->isComparable());
    }

    /**
     * @dataProvider statusMapping
     */
    public function testEveryStoredStatusMapsToTheStatedRule(?string $status, ItemAvailability $expected): void
    {
        $this->assertSame($expected, ItemAvailability::of($status, false));
    }

    /** @return iterable<string, array{?string, ItemAvailability}> */
    public static function statusMapping(): iterable
    {
        yield 'PENDING' => ['PENDING', ItemAvailability::NeverRequested];
        yield 'QUEUED' => ['QUEUED', ItemAvailability::Queued];
        yield 'SYNCED' => ['SYNCED', ItemAvailability::Synced];
        yield 'EMPTY' => ['EMPTY', ItemAvailability::AuthoritativeEmpty];
        // An unrecognised status blames nobody.
        yield 'null' => [null, ItemAvailability::NeverRequested];
        yield 'nonsense' => ['WAT', ItemAvailability::NeverRequested];
    }

    /** An unmirrored order is a different sentence from an unsynchronised item list. */
    public function testAnAbsentOriginalOrderIsItsOwnExplanation(): void
    {
        $comparator = new OrderComparator();
        $comparison = $comparator->compare(
            null,
            $this->demoOrder([$this->wings()]),
            OriginalItems::of(ItemAvailability::Synced, [$this->item($this->wings())]),
        );

        $this->assertFalse($comparison->itemsWereCompared());
        $this->assertSame(ComparisonStatus::Uncomparable, $comparison->items[0]->status);
        $this->assertStringContainsString('not in the local mirror', $comparison->itemNote());
    }

    // ------------------------------------------------------------------ the provider's exact name

    /** `[F7]` is a kitchen routing code. It is shown, never stripped from the stored value. */
    public function testTheProvidersExactNameIsPreservedForDisplay(): void
    {
        $item = $this->item($this->wings());

        $this->assertSame(self::WINGS, $item->name);
        $this->assertSame('Fried Chicken Wings (4)', $item->productName);
    }

    /** And the prefix must not make two identical lines look different. */
    public function testAPrefixedOriginalMatchesAnUnprefixedDemo(): void
    {
        // The demo document carries the un-prefixed name, which is what `data.product.name` holds.
        $demo = $this->wings();
        $demo['name'] = 'Fried Chicken Wings (4)';

        $comparison = $this->compare([$demo], $this->synced([$this->wings()]));

        $this->assertSame(ComparisonStatus::Match, $comparison->items[0]->status);
        $this->assertSame(self::WINGS, $comparison->items[0]->original?->name);
        $this->assertSame('Fried Chicken Wings (4)', $comparison->items[0]->demo?->name);
    }

    /** The row heading shows the provider's string, prefix and all. */
    public function testTheRowHeadingUsesTheOriginalsExactName(): void
    {
        $comparison = $this->compare([$this->wings()], $this->synced([$this->wings()]));

        $this->assertSame(self::WINGS, $comparison->items[0]->label());
    }

    // ------------------------------------------------------------------ facets

    public function testAQuantityDifferenceIsFoundAndTheLineStillPairs(): void
    {
        $demo = $this->wings();
        $demo['qty'] = '2';

        $comparison = $this->compare([$demo], $this->synced([$this->wings()]));

        $this->assertSame(ComparisonStatus::Different, $comparison->items[0]->status);
        $this->assertSame(ComparisonStatus::Different, $this->field($comparison->items[0], 'Quantity')->status);
        $this->assertSame('1', $this->field($comparison->items[0], 'Quantity')->original);
        $this->assertSame('2', $this->field($comparison->items[0], 'Quantity')->demo);
    }

    /** `"1"` and `"1.000"` are one quantity written two ways, not a mistake. */
    public function testQuantityIgnoresTrailingDecimalZeros(): void
    {
        $demo = $this->wings();
        $demo['qty'] = '1.000';

        $comparison = $this->compare([$demo], $this->synced([$this->wings()]));

        $this->assertSame(ComparisonStatus::Match, $comparison->items[0]->status);
    }

    public function testAUnitPriceDifferenceIsFound(): void
    {
        $demo = $this->wings();
        $demo['price'] = '12.00000';

        $comparison = $this->compare([$demo], $this->synced([$this->wings()]));

        $this->assertSame(ComparisonStatus::Different, $this->field($comparison->items[0], 'Unit price')->status);
    }

    public function testAnInstructionDifferenceIsFound(): void
    {
        $demo = $this->wings();
        $demo['desc'] = 'no salt';

        $comparison = $this->compare([$demo], $this->synced([$this->wings(instructions: 'extra salt')]));

        $this->assertSame(
            ComparisonStatus::Different,
            $this->field($comparison->items[0], 'Item instructions')->status,
        );
    }

    public function testAMissingAdditionIsFound(): void
    {
        $comparison = $this->compare(
            [$this->wings()],
            $this->synced([$this->wings(additionals: [['additional_name' => 'Sauce', 'option_name' => 'Extra Hot']])]),
        );

        $options = $this->field($comparison->items[0], 'Options');

        $this->assertSame(ComparisonStatus::MissingInDemo, $options->status);
        // Shown as a readable label, not as the canonical signature.
        $this->assertSame('Sauce: Extra Hot', $options->original);
        $this->assertNull($options->demo);
    }

    /** A combination option is compared like any other modifier. */
    public function testACombinationDifferenceIsFound(): void
    {
        $original = $this->wings(combinations: [['attribute_name' => 'Size', 'option_name' => 'Large']]);
        $demo = $this->wings(combinations: [['attribute_name' => 'Size', 'option_name' => 'Small']]);

        $comparison = $this->compare([$demo], $this->synced([$original]));

        $this->assertSame(ComparisonStatus::Different, $this->field($comparison->items[0], 'Options')->status);
        $this->assertSame('Size: Large', $this->field($comparison->items[0], 'Options')->original);
    }

    /**
     * The same two extras chosen in the other order is not a mistake.
     *
     * This is why the modifier set is compared as a sorted signature and only displayed in document
     * order. Comparing the document order reported a difference for an order that was correct.
     */
    public function testModifiersChosenInADifferentOrderStillMatch(): void
    {
        $two = [
            ['additional_name' => 'Sauce', 'option_name' => 'Extra Hot'],
            ['additional_name' => 'Side', 'option_name' => 'Fries'],
        ];

        $original = $this->wings(additionals: $two);
        $demo = $this->wings(additionals: [$two[1], $two[0]]);

        $comparison = $this->compare([$demo], $this->synced([$original]));

        $this->assertSame(ComparisonStatus::Match, $comparison->items[0]->status);
    }

    /** A modifier quantity is part of the modifier, so two sauces is not one sauce. */
    public function testAModifierQuantityIsPartOfTheComparison(): void
    {
        $original = $this->wings(additionals: [['additional_name' => 'Sauce', 'option_name' => 'Hot', 'qty' => '2']]);
        $demo = $this->wings(additionals: [['additional_name' => 'Sauce', 'option_name' => 'Hot', 'qty' => '1']]);

        $comparison = $this->compare([$demo], $this->synced([$original]));

        $this->assertSame(ComparisonStatus::Different, $this->field($comparison->items[0], 'Options')->status);
    }

    // ------------------------------------------------------------------ pairing

    /**
     * Two lines of the same product, entered in the other order.
     *
     * The naive pairing — first unclaimed line with the same name — matches plain against sauced and
     * sauced against plain, and reports two modifier differences for an order that is completely
     * correct. The minimum-cost pairing reports none.
     */
    public function testDuplicateLinesOfOneProductPairByClosestMatch(): void
    {
        $plain = $this->wings();
        $sauced = $this->wings(additionals: [['additional_name' => 'Sauce', 'option_name' => 'Extra Hot']]);

        // Original: plain then sauced. Demo: sauced then plain.
        $comparison = $this->compare([$sauced, $plain], $this->synced([$plain, $sauced]));

        $this->assertCount(2, $comparison->items);

        foreach ($comparison->items as $row) {
            $this->assertSame(
                ComparisonStatus::Match,
                $row->status,
                'A correct order entered in another sequence has no differences.',
            );
        }
    }

    /** Reordering whole lines of different products changes nothing either. */
    public function testReorderedLinesStillPair(): void
    {
        $comparison = $this->compare(
            [$this->soup(), $this->wings()],
            $this->synced([$this->wings(), $this->soup()]),
        );

        foreach ($comparison->items as $row) {
            $this->assertSame(ComparisonStatus::Match, $row->status);
        }
    }

    /** Three of the same product, one of them wrong: exactly one row differs. */
    public function testOnlyTheWrongDuplicateIsReportedAsDifferent(): void
    {
        $a = $this->wings(additionals: [['additional_name' => 'Sauce', 'option_name' => 'Hot']]);
        $b = $this->wings(additionals: [['additional_name' => 'Sauce', 'option_name' => 'Mild']]);
        $c = $this->wings();

        $wrong = $this->wings(additionals: [['additional_name' => 'Sauce', 'option_name' => 'Sweet']]);

        $comparison = $this->compare([$c, $wrong, $a], $this->synced([$a, $b, $c]));

        $statuses = array_map(static fn(ItemComparison $i): string => $i->status->value, $comparison->items);

        $this->assertSame(1, count(array_filter($statuses, static fn(string $s): bool => $s === 'DIFFERENT')));
        $this->assertSame(2, count(array_filter($statuses, static fn(string $s): bool => $s === 'MATCH')));
    }

    /** The shared catalogue id pairs two lines whose names disagree. */
    public function testASharedProductIdPairsLinesWhoseNamesDiffer(): void
    {
        $demo = $this->wings();
        $demo['name'] = 'Chicken Wings, Fried';
        $demo['data']['product']['name'] = 'Chicken Wings, Fried';

        $comparison = $this->compare([$demo], $this->synced([$this->wings()]));

        $this->assertCount(1, $comparison->items, 'A shared product id must pair them, not split them.');
        $this->assertSame(ComparisonStatus::Different, $comparison->items[0]->status);
        $this->assertSame(ComparisonStatus::Different, $this->field($comparison->items[0], 'Product')->status);
    }

    /** `product_id` 0 is the provider's "no product", so it pairs nothing. */
    public function testAZeroProductIdIsNotAnIdentity(): void
    {
        $left = $this->wings();
        $left['product_id'] = 0;
        $right = $this->soup();
        $right['product_id'] = 0;

        $comparison = $this->compare([$right], $this->synced([$left]));

        // Different names, no usable id: two unpaired lines rather than one false pairing.
        $this->assertCount(2, $comparison->items);
    }

    public function testAnOmittedProductIsMissingAndAnAddedOneIsExtra(): void
    {
        $comparison = $this->compare([$this->soup()], $this->synced([$this->wings()]));

        $this->assertCount(2, $comparison->items);
        $this->assertSame(ComparisonStatus::MissingInDemo, $comparison->items[0]->status);
        $this->assertSame(self::WINGS, $comparison->items[0]->original?->name);
        $this->assertSame(ComparisonStatus::ExtraInDemo, $comparison->items[1]->status);
    }

    /** Originals keep document order, and additions follow — so the page does not reshuffle. */
    public function testRowOrderIsOriginalsThenExtras(): void
    {
        $comparison = $this->compare(
            [$this->soup(), $this->wings()],
            $this->synced([$this->wings()]),
        );

        $this->assertSame(self::WINGS, $comparison->items[0]->original?->name);
        $this->assertNull($comparison->items[1]->original);
    }

    /** Same input, same pairing, every time — a reviewer must not see the page change under them. */
    public function testPairingIsDeterministic(): void
    {
        $a = $this->wings(additionals: [['additional_name' => 'Sauce', 'option_name' => 'Hot']]);
        $b = $this->wings(additionals: [['additional_name' => 'Sauce', 'option_name' => 'Mild']]);

        $first = $this->compare([$b, $a], $this->synced([$a, $b]));
        $second = $this->compare([$b, $a], $this->synced([$a, $b]));

        $this->assertSame(
            array_map(static fn(ItemComparison $i): string => $i->status->value, $first->items),
            array_map(static fn(ItemComparison $i): string => $i->status->value, $second->items),
        );
    }

    // ------------------------------------------------------------------ removed lines

    /** A line the provider removed is not expected in the demo, so it is not "missing". */
    public function testARemovedOriginalLineIsListedApartAndNotCountedAsMissing(): void
    {
        $removed = $this->wings();
        $removed['deleted'] = '1';

        $items = OriginalItems::of(
            ItemAvailability::Synced,
            [$this->item($removed), $this->item($this->soup())],
        );

        $comparison = $this->compare([$this->soup()], $items);

        $this->assertCount(1, $comparison->items, 'The removed line must not appear as a comparison row.');
        $this->assertSame(ComparisonStatus::Match, $comparison->items[0]->status);
        $this->assertCount(1, $comparison->removedItems);
        $this->assertSame(self::WINGS, $comparison->removedItems[0]->name);
    }

    // ------------------------------------------------------------------ one canonicaliser, two sides

    /**
     * The API sends `data` as a JSON string; the demo document has it as an object.
     *
     * Both must canonicalise identically, or every modifier on every line would read as a difference
     * caused by the transport rather than by the trainee.
     */
    public function testDataAsAStringAndAsAnObjectCanonicaliseIdentically(): void
    {
        $asObject = $this->wings(additionals: [['additional_name' => 'Sauce', 'option_name' => 'Hot']]);

        $asString = $asObject;
        $asString['data'] = (string) json_encode($asObject['data']);

        $canonicalizer = new ItemCanonicalizer();

        $this->assertSame(
            $canonicalizer->canonicalize($asObject)->toArray(),
            $canonicalizer->canonicalize($asString)->toArray(),
        );
    }

    /** `comparison_json` survives the round trip the reader performs. */
    public function testTheCanonicalFormRoundTripsThroughStorage(): void
    {
        $canonical = (new ItemCanonicalizer())->canonicalize(
            $this->wings(additionals: [['additional_name' => 'Sauce', 'option_name' => 'Hot', 'qty' => '2']]),
        );

        $restored = CanonicalItem::fromArray($canonical->toArray());

        $this->assertSame($canonical->toArray(), $restored->toArray());
        $this->assertSame($canonical->modifierSignature(), $restored->modifierSignature());
        $this->assertSame(self::WINGS, $restored->displayName);
    }

    /** Money and quantity stay strings, so five decimals survive and no float rounds anything. */
    public function testMoneyAndQuantityAreNeverFloats(): void
    {
        $canonical = (new ItemCanonicalizer())->canonicalize($this->wings());

        $this->assertSame('11.35000', $canonical->unitPrice);
        $this->assertSame('1', $canonical->qty);
    }

    // ------------------------------------------------------------------ harness

    /**
     * @param list<array<string, mixed>> $demoItems raw demo document lines
     */
    private function compare(array $demoItems, OriginalItems $items): OrderComparison
    {
        return (new OrderComparator())->compare(
            $this->sourceOrder(),
            $this->demoOrder($demoItems),
            $items,
        );
    }

    /** @param list<array<string, mixed>> $raw */
    private function synced(array $raw): OriginalItems
    {
        return OriginalItems::of(ItemAvailability::Synced, array_map($this->item(...), $raw));
    }

    /** @param array<string, mixed> $raw */
    private function item(array $raw): NormalizedItem
    {
        return NormalizedItem::fromCanonical((new ItemCanonicalizer())->canonicalize($raw));
    }

    private function sourceOrder(): NormalizedOrder
    {
        // The order itself, with no `items` key — which is exactly what the mirror holds.
        return (new OrderNormalizer())->normalize([
            'id' => 16800401,
            'account_id' => 1731,
            'status' => 'completed',
            'type' => 'delivery',
            'total_amount' => '11.35000',
        ]);
    }

    /** @param list<array<string, mixed>> $items */
    private function demoOrder(array $items): NormalizedOrder
    {
        return (new OrderNormalizer())->normalize([
            'id' => 16630821,
            'account_id' => 1731,
            'status' => 'completed',
            'type' => 'delivery',
            'total_amount' => '11.35000',
            'items' => $items,
        ]);
    }

    /**
     * The client's own sample line, in the provider's shape.
     *
     * @param list<array<string, mixed>> $additionals
     * @param list<array<string, mixed>> $combinations
     *
     * @return array<string, mixed>
     */
    private function wings(
        array $additionals = [],
        array $combinations = [],
        ?string $instructions = null,
    ): array {
        return [
            'id' => 45308981,
            'qty' => '1',
            'name' => self::WINGS,
            'price' => '11.35000',
            'cost_price' => '11.35000',
            'desc' => $instructions,
            'flag' => 'ADDED',
            'deleted' => '0',
            'account_id' => '0',
            'user_id' => '0',
            'order_id' => 16800401,
            'product_id' => 506941,
            'pack_idx' => 1,
            'data' => [
                'product' => ['id' => 506941, 'sn' => 'F7', 'name' => 'Fried Chicken Wings (4)'],
                'additionals' => $additionals === [] ? null : $additionals,
                'combinations' => $combinations === [] ? null : ['options' => $combinations],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function soup(): array
    {
        return [
            'id' => 45308991,
            'qty' => '1',
            'name' => 'Wonton Soup',
            'price' => '2.00000',
            'deleted' => '0',
            'order_id' => 16800401,
            'product_id' => 538451,
            'data' => [
                'product' => ['id' => 538451, 'sn' => 'C12', 'name' => 'Wonton Soup'],
                'additionals' => null,
                'combinations' => null,
            ],
        ];
    }

    private function field(ItemComparison $item, string $label): FieldComparison
    {
        foreach ($item->fields as $field) {
            if ($field->label === $label) {
                return $field;
            }
        }

        $this->fail('no field labelled ' . $label);
    }
}
