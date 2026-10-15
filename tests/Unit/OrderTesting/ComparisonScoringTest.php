<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\OrderTesting\Application\Comparison\ComparisonScorer;
use App\OrderTesting\Application\Comparison\OrderComparator;
use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Domain\Comparison\ComparisonScore;
use App\OrderTesting\Domain\Comparison\ItemAvailability;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\OrderTesting\Domain\Comparison\OriginalItems;
use App\OrderTesting\Domain\Comparison\SectionScore;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_sum;
use function dirname;
use function file_get_contents;
use function json_decode;
use function json_encode;

/**
 * How closely a demo order reproduced its original, as a number.
 *
 * ## These run through the real comparator
 *
 * Nothing here hand-builds an `OrderComparison`. Each case normalises the same source order and one of
 * the real demo-order fixtures, compares them with {@see OrderComparator}, and scores the result — so a
 * change to how a field is compared shows up here as a changed score instead of passing unnoticed
 * because the test asserted on a status list it wrote itself.
 *
 * ## The expected numbers were derived by hand from the statuses
 *
 * Each one is checked in its own docblock, unit by unit, against the weighting. A test that only
 * asserted "whatever it currently returns" would protect the implementation rather than the
 * specification.
 */
final class ComparisonScoringTest extends TestCase
{
    /** The original order, as the Order58 mirror holds it. Same fixture as {@see OrderComparisonTest}. */
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

    private function score(
        ?NormalizedOrder $source,
        string $basename,
        ?OriginalItems $items = null,
    ): ComparisonScore {
        $comparison = (new OrderComparator())->compare(
            $source,
            $this->demo($basename),
            $items ?? OriginalItems::of(ItemAvailability::Synced, $source?->items ?? []),
        );

        return (new ComparisonScorer())->score($comparison);
    }

    private function section(ComparisonScore $score, string $title): SectionScore
    {
        $section = $score->section($title);

        if ($section === null) {
            self::fail('No section score for "' . $title . '"');
        }

        return $section;
    }

    // --------------------------------------------------------------------------- a whole score, by hand

    /**
     * One wrong quantity on one of two lines.
     *
     * Customer 6/6, Order 3/3, Totals all seven weighted units agreed — each 100%.
     * Wonton Soup: Product 1 + Unit price 2 + Additions 1 + SN 1 earned of 7 weighted (Quantity ×2
     * wrong), so 5/7. Spring Roll agreed on all six of its weighted units, so 1. The line mean is
     * (5/7 + 1) / 2 = 0.857142…, i.e. 85.714…%.
     * Overall = (100·40 + 100·40 + 85.714…·90 + 100·10) / 180 = 92.857…%.
     */
    public function testOneWrongQuantityOnOneLine(): void
    {
        $score = $this->score($this->source(), '16758551-16630651.json');

        self::assertSame(100, $this->section($score, 'Customer')->displayPercent());
        self::assertSame(100, $this->section($score, 'Order')->displayPercent());
        self::assertSame(100, $this->section($score, 'Totals')->displayPercent());
        self::assertSame(86, $this->section($score, 'Order items')->displayPercent());

        self::assertEqualsWithDelta(85.714286, $this->section($score, 'Order items')->percent ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(92.857143, $score->overall ?? 0.0, 0.0001);
        self::assertSame(93, $score->displayPercent());
    }

    /**
     * One missing modifier on one of two lines.
     *
     * Wonton Soup loses only its Additions unit (1 of 7 weighted), so 6/7; `Options` is the union of
     * Additions and Combinations and is deliberately not weighed, or one forgotten modifier would be
     * charged twice. Lines: (6/7 + 1) / 2 = 92.857…%.
     * Overall = (4000 + 4000 + 92.857…·90 + 1000) / 180 = 96.428…%.
     */
    public function testOneMissingModifierCostsOneUnitNotThree(): void
    {
        $score = $this->score($this->source(), '16758551-16630661.json');

        self::assertSame(93, $this->section($score, 'Order items')->displayPercent());
        self::assertEqualsWithDelta(96.428571, $score->overall ?? 0.0, 0.0001);
        self::assertSame(96, $score->displayPercent());
    }

    /**
     * A line missed, a line invented, and a wrong grand total.
     *
     * Items: Wonton Soup 1, Spring Roll missing 0, Egg Roll extra 0 → mean 1/3 = 33.333…%.
     * Totals: Subtotal, Shipping fee, Tax and Tip agreed (1 each) but Total did not, and Total carries
     * 3 of the section's 7 units → 4/7 = 57.142…%.
     * Overall = (4000 + 4000 + 33.333…·90 + 57.142…·10) / 180 = 64.285…%.
     */
    public function testAMissedLineAnInventedLineAndAWrongTotal(): void
    {
        $score = $this->score($this->source(), '16758551-16630671.json');

        self::assertSame(33, $this->section($score, 'Order items')->displayPercent());
        self::assertSame(57, $this->section($score, 'Totals')->displayPercent());
        self::assertEqualsWithDelta(64.285714, $score->overall ?? 0.0, 0.0001);
        self::assertSame(64, $score->displayPercent());
    }

    /**
     * The weighting is a share of a hundred, and it is derived rather than typed.
     *
     * Expressing it out of 100 is a change of units: a weighted mean cannot move when every weight is
     * scaled by the same factor, which is what makes this safe to do to a number people have already
     * been shown. What would not be safe is writing 22.22 into the table — four rounded shares do not
     * total a hundred, and the arithmetic would quietly use the rounding.
     */
    public function testTheWeightingIsAShareOfAHundredAndKeepsItsProportions(): void
    {
        $weighting = ComparisonScorer::weighting();

        self::assertSame(
            ['Customer', 'Order', 'Order items', 'Totals'],
            array_keys($weighting),
        );

        // Exactly a hundred, not approximately: the full-precision shares sum without residue.
        self::assertSame(100.0, array_sum($weighting));

        // Full precision, so the stored value is not the printed one.
        self::assertEqualsWithDelta(22.222222222222, $weighting['Customer'], 0.000000001);
        self::assertEqualsWithDelta(5.5555555555556, $weighting['Totals'], 0.000000001);
        self::assertSame(50.0, $weighting['Order items']);

        // The proportions the old tally expressed, unchanged: items are nine times the totals, and
        // customer and order are four times it each.
        self::assertEqualsWithDelta(9.0, $weighting['Order items'] / $weighting['Totals'], 0.000000001);
        self::assertEqualsWithDelta(4.0, $weighting['Customer'] / $weighting['Totals'], 0.000000001);

        // And the section carries that same share, so the page states the number the sum used.
        $score = $this->score($this->source(), '16758551-16630651.json');

        self::assertSame($weighting['Customer'], $this->section($score, 'Customer')->weight);
        self::assertSame($weighting['Order items'], $this->section($score, 'Order items')->weight);
    }

    /**
     * The weighting's stated consequence, held by a test.
     *
     * Items carry 90 of the 180, so a header reproduced perfectly cannot carry an order whose every
     * line is wrong past half. If this number ever changes, the weighting changed — which is a business
     * decision and not a refactor.
     */
    public function testEveryLineWrongCannotExceedHalfHoweverPerfectTheHeader(): void
    {
        // Every original line missing from the demo and every demo line invented: the one fixture whose
        // items disagree completely, with its totals forced to agree so only the items are at stake.
        $source = $this->source();
        $comparison = (new OrderComparator())->compare(
            $source,
            $this->demo('16758551-16630651.json'),
            // An item list the demo cannot match: one line, a product nobody ordered.
            OriginalItems::of(ItemAvailability::Synced, $this->demo('16754561-16630681.json')->items),
        );

        $score = (new ComparisonScorer())->score($comparison);

        self::assertSame(0, $this->section($score, 'Order items')->displayPercent());
        self::assertSame(100, $this->section($score, 'Customer')->displayPercent());
        self::assertSame(100, $this->section($score, 'Order')->displayPercent());
        self::assertSame(100, $this->section($score, 'Totals')->displayPercent());
        self::assertEqualsWithDelta(50.0, $score->overall ?? 0.0, 0.0001);
    }

    // --------------------------------------------------------------- what is counted, and what is not

    /** Delivery address is shown and never scored: its street is redacted out of both documents. */
    public function testDeliveryAddressIsShownButNeverScored(): void
    {
        $address = $this->section($this->score($this->source(), '16758551-16630651.json'), 'Delivery address');

        self::assertFalse($address->isScored());
        self::assertTrue($address->informationalOnly);
        self::assertSame(0.0, $address->weight);
        // No coverage either: a denominator beside "Not scored" would describe a measurement nobody took.
        self::assertSame(0, $address->scoredUnits);
    }

    /**
     * A field both documents left empty is not a unit.
     *
     * Surcharge and Discount are absent from both sides, the page drops those rows, and the score
     * counts five units out of five visible — not seven out of seven. Scoring the silence would pad
     * every total in the database with agreement nobody earned.
     */
    public function testFieldsBlankOnBothSidesAreNeitherShownNorScored(): void
    {
        $totals = $this->section($this->score($this->source(), '16758551-16630651.json'), 'Totals');

        self::assertSame(5, $totals->visibleUnits);
        self::assertSame(5, $totals->scoredUnits);
        self::assertSame(2, $totals->hiddenAbsences);
    }

    /**
     * A value the demo holds and the original does not costs nothing on a header field.
     *
     * Measured across all 396 mirrored originals in this database, the provider records a first name on
     * 1.5% of them and an email on none — so "the demo has it and the original does not" is a gap in
     * the source, not an invention by the trainee. `Destination` is exactly that case.
     */
    public function testExtraInDemoIsCountedButNotChargedOnHeaderFields(): void
    {
        $address = $this->section($this->score($this->source(), '16758551-16630651.json'), 'Delivery address');

        self::assertSame(1, $address->extra);
        self::assertFalse($address->extraIsScored);
    }

    /** On the item list the provider has stated its lines, so an invented line is charged. */
    public function testExtraItemsAreCharged(): void
    {
        $items = $this->section($this->score($this->source(), '16758551-16630671.json'), 'Order items');

        self::assertSame(1, $items->extra);
        self::assertTrue($items->extraIsScored);
        self::assertSame(3, $items->scoredUnits);
    }

    // ------------------------------------------------------------------------- when there is no score

    /**
     * Items that have never been fetched leave the order unscored, not scored low.
     *
     * Half the weight lives in the items. A number built from the other half would be a different
     * measurement wearing this one's name, so the page is told why instead.
     */
    public function testUnsynchronisedItemsMakeTheOrderUnscorable(): void
    {
        $score = $this->score(
            $this->source(),
            '16758551-16630651.json',
            OriginalItems::of(ItemAvailability::NeverRequested, []),
        );

        self::assertFalse($score->isAvailable());
        self::assertNull($score->displayPercent());
        self::assertNotNull($score->unavailableReason);
        self::assertFalse($this->section($score, 'Order items')->isScored());
    }

    /** No mirrored original, no comparison, no score — and the reason says which. */
    public function testAnOrderMissingFromTheMirrorIsUnscorable(): void
    {
        $score = $this->score(null, '16758551-16630651.json');

        self::assertFalse($score->isAvailable());
        self::assertStringContainsString('not in the local mirror', (string) $score->unavailableReason);

        foreach ($score->sections as $section) {
            self::assertFalse($section->isScored(), $section->title . ' should not be scored');
        }
    }

    /**
     * An order the provider says has no lines, reproduced with no lines, is right.
     *
     * Two stated absences agreeing, which is not the same as two unfilled fields: the provider
     * confirmed the fact. Any other empty-list state is not comparable and scores nothing.
     */
    public function testAnAuthoritativelyEmptyItemListScoresFull(): void
    {
        $comparison = (new OrderComparator())->compare(
            $this->source(),
            (new OrderNormalizer())->normalize([
                'id' => 16630651, 'account_id' => 1731, 'is_test' => 1, 'items' => [],
            ]),
            OriginalItems::of(ItemAvailability::AuthoritativeEmpty, []),
        );

        $items = (new ComparisonScorer())->score($comparison)->section('Order items');

        self::assertNotNull($items);
        self::assertSame(100, $items->displayPercent());
        self::assertSame(0, $items->scoredUnits);
    }

    // ------------------------------------------------------------------------------------- presentation

    /**
     * Rounded half up, except where rounding would overstate the two ends.
     *
     * 99.6% is a different statement from 100%, and on a page about accuracy that difference is the
     * whole point — so "not quite everything" never prints 100 and "not quite nothing" never prints 0.
     */
    public function testDisplayRoundsHalfUpWithoutEverReachingTheEnds(): void
    {
        self::assertSame(65, SectionScore::display(64.5));
        self::assertSame(64, SectionScore::display(64.4));
        self::assertSame(99, SectionScore::display(99.6));
        self::assertSame(1, SectionScore::display(0.2));
        self::assertSame(100, SectionScore::display(100.0));
        self::assertSame(0, SectionScore::display(0.0));
    }

    /** A number resting on less than half the rows on screen says so, rather than moving. */
    public function testLowCoverageIsFlaggedWithoutChangingTheNumber(): void
    {
        $thin = new SectionScore(
            title: 'Customer',
            percent: 100.0,
            matched: 1,
            different: 0,
            missing: 0,
            extra: 2,
            notApplicable: 0,
            scoredUnits: 1,
            visibleUnits: 3,
        );

        self::assertTrue($thin->hasLowCoverage());
        self::assertSame(100, $thin->displayPercent());

        $solid = new SectionScore(
            title: 'Customer',
            percent: 50.0,
            matched: 1,
            different: 1,
            missing: 0,
            extra: 0,
            notApplicable: 0,
            scoredUnits: 2,
            visibleUnits: 2,
        );

        self::assertFalse($solid->hasLowCoverage());
    }

    /**
     * The same pair scores the same, every time and for everybody.
     *
     * The scorer holds no state and reads nothing outside the comparison it is handed, so an agent and
     * an administrator looking at one demo order cannot be shown two different numbers.
     */
    public function testScoringIsDeterministic(): void
    {
        $first = $this->score($this->source(), '16758551-16630671.json');
        $second = $this->score($this->source(), '16758551-16630671.json');

        self::assertSame($first->overall, $second->overall);
        self::assertSame($first->displayPercent(), $second->displayPercent());
    }
}
