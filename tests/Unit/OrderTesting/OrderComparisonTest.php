<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\OrderTesting\Application\Comparison\OrderComparator;
use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Domain\Comparison\ComparisonSection;
use App\OrderTesting\Domain\Comparison\ComparisonStatus;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemComparison;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\OrderTesting\Domain\Comparison\OrderComparison;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function json_decode;
use function json_encode;
use function property_exists;

/**
 * Source order against demo order: what the page shows, and what scoring will later read.
 *
 * Every assertion here is about a **fact** — two values and a status. Nothing counts the differences or
 * weighs them, because nothing in this phase is allowed to; see {@see ComparisonStatus}.
 *
 * The fixtures are deliberately not identical to the source. One has a changed quantity, one is missing
 * a modifier, and one has a changed total with a line swapped — because a comparison tested only
 * against an exact copy proves that it can say "match" and nothing else.
 */
final class OrderComparisonTest extends TestCase
{
    /** The original order, as the Order58 mirror would hold it. */
    private function source(): NormalizedOrder
    {
        $raw = [
            'id' => 16758551,
            'account_id' => 1731,
            'status' => 'completed',
            'type' => 'delivery',
            'payment_method' => 'cash',
            'subtotal' => '3.50000',
            'shipping_fee' => '0.50000',
            'tax' => '0.28000',
            'tip' => '0.00000',
            'total_amount' => '4.28000',
            'created_at' => 1791353759,
            'data' => (string) json_encode([
                'reservation' => [
                    'first_name' => 'testa',
                    'last_name' => 'Customer',
                    'phone' => '19178532637',
                    'email' => 'testa@example.com',
                    'customer_count' => 2,
                    'instruction' => 'Ring the bell',
                ],
                'shipping' => [
                    'street1' => '162 N Main St',
                    'street2' => 'Apt 2',
                    'city' => 'Freeport',
                    'state' => 'NY',
                    'zipcode' => '11520',
                ],
            ]),
            'items' => [
                [
                    'name' => 'Wonton Soup', 'quantity' => 2, 'price' => '2.00000',
                    'total' => '4.00000', 'sn' => 'C12',
                    'options' => [['name' => 'Extra noodles']],
                ],
                [
                    'name' => 'Spring Roll', 'quantity' => 1, 'price' => '1.50000',
                    'total' => '1.50000', 'sn' => 'A04', 'options' => [],
                ],
            ],
        ];

        return (new OrderNormalizer())->normalize($raw);
    }

    private function demo(string $basename): NormalizedOrder
    {
        $json = (string) file_get_contents(
            dirname(__DIR__, 2) . '/_data/order-testing/demo_mix_orders/' . $basename,
        );

        /** @var array<array-key, mixed> $raw */
        $raw = json_decode($json, true);

        return (new OrderNormalizer())->normalize($raw);
    }

    private function compare(?NormalizedOrder $source, string $basename): OrderComparison
    {
        return (new OrderComparator())->compare($source, $this->demo($basename));
    }

    private function field(OrderComparison $comparison, string $section, string $label): FieldComparison
    {
        foreach ($comparison->sections as $s) {
            /** @var ComparisonSection $s */
            if ($s->title !== $section) {
                continue;
            }

            foreach ($s->fields as $field) {
                if ($field->label === $label) {
                    return $field;
                }
            }
        }

        self::fail('No field "' . $label . '" in section "' . $section . '"');
    }

    private function item(OrderComparison $comparison, string $label): ItemComparison
    {
        foreach ($comparison->items as $item) {
            if ($item->label() === $label) {
                return $item;
            }
        }

        self::fail('No item row for "' . $label . '"');
    }

    // ------------------------------------------------------------------ fields that agree and disagree

    public function testIdenticalCustomerFieldsMatch(): void
    {
        $comparison = $this->compare($this->source(), '16758551-16630651.json');

        self::assertSame(ComparisonStatus::Match, $this->field($comparison, 'Customer', 'Phone')->status);
        self::assertSame(ComparisonStatus::Match, $this->field($comparison, 'Customer', 'First name')->status);
        self::assertSame(ComparisonStatus::Match, $this->field($comparison, 'Customer', 'Email')->status);
    }

    public function testAddressFieldsCompare(): void
    {
        $comparison = $this->compare($this->source(), '16758551-16630651.json');

        self::assertSame(ComparisonStatus::Match, $this->field($comparison, 'Delivery address', 'City')->status);
        self::assertSame(ComparisonStatus::Match, $this->field($comparison, 'Delivery address', 'State')->status);
        self::assertSame(
            ComparisonStatus::Match,
            $this->field($comparison, 'Delivery address', 'Postal code')->status,
        );
    }

    /**
     * The street cannot be compared, and the page says so rather than blaming the trainee.
     *
     * `street1` is on the payment-secret list, so it is stripped from both stored payloads. Reporting
     * that as "extra in demo" would accuse a trainee of inventing an address.
     */
    public function testTheRedactedStreetIsReportedAsNotComparable(): void
    {
        $comparison = $this->compare($this->source(), '16758551-16630651.json');

        self::assertSame(
            ComparisonStatus::Uncomparable,
            $this->field($comparison, 'Delivery address', 'Street')->status,
        );
    }

    public function testADifferentTotalIsReportedAsDifferent(): void
    {
        // Fixture 3 changes the total from 4.28 to 5.75.
        $comparison = $this->compare($this->source(), '16758551-16630671.json');
        $total = $this->field($comparison, 'Totals', 'Total');

        self::assertSame(ComparisonStatus::Different, $total->status);
        self::assertSame('4.28000', $total->original);
        self::assertSame('5.75000', $total->demo);
    }

    public function testMatchingTotalsMatchDespiteTrailingZeros(): void
    {
        $comparison = $this->compare($this->source(), '16758551-16630651.json');

        // "4.28000" against "4.28000" here, but the rule that decides it is canonical-decimal equality,
        // which is what makes a provider writing "4.28" one day not read as a trainee error.
        self::assertSame(ComparisonStatus::Match, $this->field($comparison, 'Totals', 'Total')->status);
        self::assertSame(ComparisonStatus::Match, $this->field($comparison, 'Totals', 'Tax')->status);
    }

    // ------------------------------------------------------------------ items, paired by name

    public function testAChangedQuantityIsFoundOnTheRightItem(): void
    {
        // Fixture 1 orders three Wonton Soup instead of two.
        $comparison = $this->compare($this->source(), '16758551-16630651.json');
        $soup = $this->item($comparison, 'Wonton Soup');

        self::assertSame(ComparisonStatus::Different, $soup->status);

        $quantity = null;
        foreach ($soup->fields as $field) {
            if ($field->label === 'Quantity') {
                $quantity = $field;
            }
        }

        self::assertNotNull($quantity);
        self::assertSame('2', $quantity->original);
        self::assertSame('3', $quantity->demo);
        self::assertSame(ComparisonStatus::Different, $quantity->status);

        // The line the trainee got right is still reported as right.
        self::assertSame(ComparisonStatus::Match, $this->item($comparison, 'Spring Roll')->status);
    }

    public function testAMissingModifierIsFound(): void
    {
        // Fixture 2 drops "Extra noodles" and gets everything else right.
        $comparison = $this->compare($this->source(), '16758551-16630661.json');
        $soup = $this->item($comparison, 'Wonton Soup');

        self::assertSame(ComparisonStatus::Different, $soup->status);

        $options = null;
        foreach ($soup->fields as $field) {
            if ($field->label === 'Options') {
                $options = $field;
            }
        }

        self::assertNotNull($options);
        self::assertSame('Extra noodles', $options->original);
        self::assertNull($options->demo);
        self::assertSame(ComparisonStatus::MissingInDemo, $options->status);
    }

    public function testAnOmittedLineAndAnAddedLineAreBothReported(): void
    {
        // Fixture 3 replaces Spring Roll with Egg Roll.
        $comparison = $this->compare($this->source(), '16758551-16630671.json');

        self::assertSame(ComparisonStatus::MissingInDemo, $this->item($comparison, 'Spring Roll')->status);
        self::assertSame(ComparisonStatus::ExtraInDemo, $this->item($comparison, 'Egg Roll')->status);
    }

    /**
     * Pairing never uses a document id.
     *
     * The client was explicit that a demo document's record ids may be synthetic. This proves the
     * pairing survives ids that disagree completely — the same two lines still pair, because only the
     * product name is used.
     */
    public function testItemsPairByNameEvenWhenEveryIdDisagrees(): void
    {
        $demoRaw = [
            'id' => 16630651,
            'account_id' => 1731,
            'items' => [
                [
                    'id' => 999999001, 'product_id' => 42, 'name' => 'Wonton Soup', 'quantity' => 2,
                    'price' => '2.00000', 'total' => '4.00000', 'sn' => 'C12',
                    'options' => [['name' => 'Extra noodles']],
                ],
                [
                    'id' => 999999002, 'product_id' => 77, 'name' => 'Spring Roll', 'quantity' => 1,
                    'price' => '1.50000', 'total' => '1.50000', 'sn' => 'A04', 'options' => [],
                ],
            ],
        ];

        $comparison = (new OrderComparator())->compare(
            $this->source(),
            (new OrderNormalizer())->normalize($demoRaw),
        );

        self::assertSame(ComparisonStatus::Match, $this->item($comparison, 'Wonton Soup')->status);
        self::assertSame(ComparisonStatus::Match, $this->item($comparison, 'Spring Roll')->status);
    }

    // ------------------------------------------------------------------ the source order may be absent

    /**
     * A demo order outlives the absence of the order it came from.
     *
     * The mirror is filled by syncing a date range somebody asked for; the demo file arrives on its
     * own. Discarding the trainee's work because the left-hand column is empty would lose the only
     * copy of it this application has.
     */
    public function testAMissingSourceOrderLeavesTheDemoFullyVisible(): void
    {
        $comparison = $this->compare(null, '16758551-16630651.json');

        self::assertFalse($comparison->hasOriginal());
        self::assertNotNull($comparison->demo);
        self::assertSame('19178532637', $comparison->demo->customer->phone);
        self::assertCount(2, $comparison->demo->items);
    }

    public function testEveryFieldIsUncomparableWithoutASourceOrder(): void
    {
        $comparison = $this->compare(null, '16758551-16630651.json');

        foreach ($comparison->sections as $section) {
            foreach ($section->fields as $field) {
                self::assertSame(
                    ComparisonStatus::Uncomparable,
                    $field->status,
                    // The failure this guards: without the whole-order check, a present demo value
                    // beside an absent original reads "extra in demo", which accuses the trainee of
                    // adding something when the truth is that nobody synced the order.
                    $section->title . ' / ' . $field->label . ' must not be judged',
                );
            }
        }

        foreach ($comparison->items as $item) {
            self::assertSame(ComparisonStatus::Uncomparable, $item->status);
        }
    }

    // ------------------------------------------------------------------ scoring is NOT part of this

    /**
     * The comparison carries facts and no verdict.
     *
     * Asserted structurally rather than by reading the page: a score added later must be able to read
     * this object, and this test is what says the object does not already contain one.
     */
    public function testTheComparisonProducesNoScore(): void
    {
        $comparison = $this->compare($this->source(), '16758551-16630651.json');

        foreach (['score', 'percentage', 'accuracy', 'passed', 'points', 'weight', 'grade'] as $forbidden) {
            self::assertFalse(
                property_exists($comparison, $forbidden),
                'OrderComparison must not carry a ' . $forbidden . ' in this phase',
            );
        }
    }
}
