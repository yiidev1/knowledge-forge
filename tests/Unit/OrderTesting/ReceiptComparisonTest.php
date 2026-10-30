<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\OrderTesting\Application\Comparison\ReceiptComparison;
use App\OrderTesting\Domain\Comparison\ComparisonSection;
use App\OrderTesting\Domain\Comparison\ComparisonStatus;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemAvailability;
use App\OrderTesting\Domain\Comparison\ItemComparison;
use App\OrderTesting\Domain\Comparison\NormalizedAddress;
use App\OrderTesting\Domain\Comparison\NormalizedCustomer;
use App\OrderTesting\Domain\Comparison\NormalizedItem;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\OrderTesting\Domain\Comparison\NormalizedTotals;
use App\OrderTesting\Domain\Comparison\OrderComparison;
use PHPUnit\Framework\TestCase;

/**
 * The counts above the two receipts, and the rows they are counted from.
 *
 * ## Why the summary and the document cannot be allowed to drift
 *
 * The page states the same facts twice: as rows inside two receipts, and as a line of counts above
 * them. A summary that disagrees with the document under it is worse than no summary at all, because a
 * reader checking an order has no way to tell which of the two is lying. So the rows and the counts
 * come from one pass, and these tests assert the arithmetic that pass performs.
 *
 * ## Silence is not agreement
 *
 * The case worth stating plainly: a field neither order filled in is not a match. Counting it as one
 * would let an order with almost nothing in it report a high score, which is the opposite of what this
 * page is for. Such a field is dropped from the rows and from the counts alike.
 */
final class ReceiptComparisonTest extends TestCase
{
    public function testAFieldNeitherOrderFilledInIsNeitherShownNorCounted(): void
    {
        $receipt = ReceiptComparison::from($this->comparison([
            new ComparisonSection('Customer', [
                new FieldComparison('First name', 'Marta', 'Marta', ComparisonStatus::Match),
                new FieldComparison('Email', null, null, ComparisonStatus::Match),
                new FieldComparison('Last name', '   ', '', ComparisonStatus::Match),
            ]),
        ]));

        self::assertCount(1, $receipt->sections[0]['fields']);
        self::assertSame('First name', $receipt->sections[0]['fields'][0]->label);
        self::assertSame(1, $receipt->count(ComparisonStatus::Match));
        self::assertSame(1, $receipt->total());
    }

    /** A zero is an answer and keeps its Summary row; the Summary carries no verdicts, so none is counted. */
    public function testAZeroIsAnAnswerAndKeepsItsRowInTheUncountedSummary(): void
    {
        $receipt = ReceiptComparison::from($this->comparison([
            new ComparisonSection('Totals', [
                new FieldComparison('Tip', '0.00', '0.00', ComparisonStatus::Match),
                new FieldComparison('Discount', '0', null, ComparisonStatus::MissingInDemo),
            ]),
        ]));

        self::assertCount(2, $receipt->sections[0]['fields']);
        self::assertSame(0, $receipt->total());
    }

    /** Customer count and Status are not drawn on the receipt, and so not counted. */
    public function testTheCountAndTheStatusAreNotDrawn(): void
    {
        $receipt = ReceiptComparison::from($this->comparison([
            new ComparisonSection('Customer', [
                new FieldComparison('Phone', '1', '1', ComparisonStatus::Match),
                new FieldComparison('Customer count', '1', '2', ComparisonStatus::Different),
            ]),
            new ComparisonSection('Order', [
                new FieldComparison('Order type', 'delivery', 'delivery', ComparisonStatus::Match),
                new FieldComparison('Status', 'completed', 'cancelled', ComparisonStatus::Different),
            ]),
        ]));

        self::assertSame(['Phone'], array_map(static fn(FieldComparison $f): string => $f->label, $receipt->sections[0]['fields']));
        self::assertSame(['Order type'], array_map(static fn(FieldComparison $f): string => $f->label, $receipt->sections[1]['fields']));
        self::assertSame(0, $receipt->count(ComparisonStatus::Different));
    }

    public function testASectionLeftWithNothingIsDropped(): void
    {
        $receipt = ReceiptComparison::from($this->comparison([
            new ComparisonSection('Customer', [
                new FieldComparison('First name', 'Marta', null, ComparisonStatus::MissingInDemo),
            ]),
            new ComparisonSection('Delivery address', [
                new FieldComparison('Street', null, null, ComparisonStatus::Match),
                new FieldComparison('City', '', '  ', ComparisonStatus::Match),
            ]),
        ]));

        self::assertCount(1, $receipt->sections);
        self::assertSame('Customer', $receipt->sections[0]['title']);
    }

    public function testEveryLineItemIsCountedWhicheverSideItIsOn(): void
    {
        $receipt = ReceiptComparison::from($this->comparison(
            [],
            [
                new ItemComparison($this->item('Wings'), $this->item('Wings'), ComparisonStatus::Match),
                new ItemComparison($this->item('Soup'), null, ComparisonStatus::MissingInDemo),
                new ItemComparison(null, $this->item('Rangoon'), ComparisonStatus::ExtraInDemo),
                new ItemComparison($this->item('Rice'), $this->item('Rice'), ComparisonStatus::Different, [
                    new FieldComparison('Quantity', '1', '2', ComparisonStatus::Different),
                ]),
                // Different only in its price, which is not judged: counted as the match it scored.
                new ItemComparison($this->item('Tea'), $this->item('Tea'), ComparisonStatus::Different, [
                    new FieldComparison('Unit price', '2.75', '3.00', ComparisonStatus::Different),
                ]),
            ],
        ));

        self::assertSame(2, $receipt->count(ComparisonStatus::Match));
        self::assertSame(1, $receipt->count(ComparisonStatus::MissingInDemo));
        self::assertSame(1, $receipt->count(ComparisonStatus::ExtraInDemo));
        self::assertSame(1, $receipt->count(ComparisonStatus::Different));
        self::assertSame(5, $receipt->total());
    }

    /** "We cannot tell" is counted on its own, never folded into the matches. */
    public function testAnUncomparableFieldIsCountedApartFromTheMatches(): void
    {
        $receipt = ReceiptComparison::from($this->comparison([
            new ComparisonSection('Delivery address', [
                new FieldComparison('Street', null, '162 N Main St', ComparisonStatus::Uncomparable),
            ]),
        ]));

        self::assertSame(0, $receipt->count(ComparisonStatus::Match));
        self::assertSame(1, $receipt->count(ComparisonStatus::Uncomparable));
    }

    /** The counts are the rows: whatever the page draws is what the strip above it adds up. */
    public function testTheCountsAddUpToTheRowsThatAreDrawn(): void
    {
        $receipt = ReceiptComparison::from($this->comparison(
            [
                new ComparisonSection('Customer', [
                    new FieldComparison('First name', 'Marta', 'Marta', ComparisonStatus::Match),
                    new FieldComparison('Email', null, null, ComparisonStatus::Match),
                    new FieldComparison('Phone', '1', '2', ComparisonStatus::Different),
                ]),
                new ComparisonSection('Totals', [
                    new FieldComparison('Total', '10.00', '9.00', ComparisonStatus::Different),
                ]),
            ],
            [new ItemComparison($this->item('Wings'), null, ComparisonStatus::MissingInDemo)],
        ));

        $drawn = 0;

        // Every row drawn with a verdict — the Summary is drawn without one.
        foreach ($receipt->sections as $section) {
            if ($section['title'] !== 'Totals') {
                $drawn += count($section['fields']);
            }
        }

        $drawn += count($receipt->comparison->items);

        self::assertSame($drawn, $receipt->total());
        self::assertSame(3, $drawn);
    }

    /**
     * @param list<ComparisonSection> $sections
     * @param list<ItemComparison>    $items
     */
    private function comparison(array $sections, array $items = []): OrderComparison
    {
        // Only the sections and the items are read by what is under test; the orders themselves are
        // the template's business, so they are present and empty rather than elaborately faked.
        return new OrderComparison(
            original: null,
            demo: $this->emptyOrder(),
            sections: $sections,
            items: $items,
            itemAvailability: ItemAvailability::Synced,
        );
    }

    private function emptyOrder(): NormalizedOrder
    {
        return new NormalizedOrder(
            orderId: null,
            customer: new NormalizedCustomer(),
            orderType: null,
            paymentMethod: null,
            status: null,
            address: new NormalizedAddress(),
            totals: new NormalizedTotals(),
            items: [],
        );
    }

    private function item(string $name): NormalizedItem
    {
        return new NormalizedItem(name: $name, sn: null, quantity: 1, unitPrice: '1.00', total: null);
    }
}
