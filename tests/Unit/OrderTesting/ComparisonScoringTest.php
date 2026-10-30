<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\OrderTesting\Application\Comparison\ComparisonScorer;
use App\OrderTesting\Application\Comparison\OrderComparator;
use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Domain\Comparison\ComparisonScore;
use App\OrderTesting\Domain\Comparison\ComparisonSection;
use App\OrderTesting\Domain\Comparison\ComparisonStatus;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemAvailability;
use App\OrderTesting\Domain\Comparison\ItemComparison;
use App\OrderTesting\Domain\Comparison\NormalizedItem;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\OrderTesting\Domain\Comparison\OrderComparison;
use App\OrderTesting\Domain\Comparison\OriginalItems;
use App\OrderTesting\Domain\Comparison\SectionScore;
use App\Shared\Order58\Items\CanonicalItem;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function array_sum;
use function dirname;
use function file_get_contents;
use function is_array;
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
 *
 * ## The fixtures are this test's own
 *
 * They live in `_data/order-testing/scoring/` — copies of the synthetic demo documents these numbers
 * were derived from — so a real demo order dropped into the import folder cannot change what is being
 * asserted here. The reported order (#15275271 against demo #16635331) is in `regression/`, with its
 * customer's phone, address and card replaced by synthetic values that are equal wherever the real
 * ones were.
 */
final class ComparisonScoringTest extends TestCase
{
    /** The original order, as the Order58 mirror holds it. Same fixture as {@see OrderComparisonTest}. */
    private function source(): NormalizedOrder
    {
        return (new OrderNormalizer())->normalize($this->sourceDocument());
    }

    /** @return array<array-key, mixed> */
    private function sourceDocument(): array
    {
        return [
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
    }

    private function demo(string $basename): NormalizedOrder
    {
        return (new OrderNormalizer())->normalize($this->demoDocument($basename));
    }

    /** @return array<array-key, mixed> */
    private function demoDocument(string $basename): array
    {
        $json = (string) file_get_contents(dirname(__DIR__, 2) . '/_data/order-testing/scoring/' . $basename);

        /** @var array<array-key, mixed> $raw */
        $raw = json_decode($json, true);

        return $raw;
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

    /** @param array<array-key, mixed> $raw */
    private function compareDocument(?NormalizedOrder $source, array $raw): OrderComparison
    {
        return (new OrderComparator())->compare(
            $source,
            (new OrderNormalizer())->normalize($raw),
            OriginalItems::of(ItemAvailability::Synced, $source?->items ?? []),
        );
    }

    /** @param array<array-key, mixed> $raw */
    private function scoreDocument(?NormalizedOrder $source, array $raw): ComparisonScore
    {
        return (new ComparisonScorer())->score($this->compareDocument($source, $raw));
    }

    /** The same order, paid by card, with the card details a stored order may keep. */
    private function paidByCard(NormalizedOrder $order, ?string $lastFour, ?string $expiry): NormalizedOrder
    {
        return new NormalizedOrder(
            orderId: $order->orderId,
            customer: $order->customer,
            orderType: $order->orderType,
            paymentMethod: 'credit card',
            status: $order->status,
            address: $order->address,
            totals: $order->totals,
            items: $order->items,
            createdAt: $order->createdAt,
            updatedAt: $order->updatedAt,
            cardLastFour: $lastFour,
            expiryDate: $expiry,
        );
    }

    /**
     * The reported pair, read exactly as the page reads it: both orders through the one normalizer, the
     * original's lines from their stored canonical form, and the demo's street from its own column.
     */
    /** @param (callable(array<array-key, mixed>): array<array-key, mixed>)|null $editDemo */
    private function regression(?callable $editDemo = null): OrderComparison
    {
        $json = (string) file_get_contents(
            dirname(__DIR__, 2) . '/_data/order-testing/regression/15275271-16635331.json',
        );
        $fixture = json_decode($json, true);
        self::assertIsArray($fixture);

        $demo = $fixture['demo'];
        self::assertIsArray($demo);
        $demo['data']['shipping']['street1'] = $fixture['demoStreet'];

        if ($editDemo !== null) {
            $demo = $editDemo($demo);
        }

        $items = [];

        foreach ($fixture['originalItems'] as $item) {
            self::assertTrue(is_array($item));
            $items[] = NormalizedItem::fromCanonical(CanonicalItem::fromArray($item));
        }

        return (new OrderComparator())->compare(
            (new OrderNormalizer())->normalize($fixture['original']),
            (new OrderNormalizer())->normalize($demo),
            OriginalItems::of(ItemAvailability::Synced, $items),
        );
    }

    /** @return array<string, ComparisonStatus> */
    private function verdictsByName(OrderComparison $comparison): array
    {
        $verdicts = [];

        foreach ($comparison->items as $item) {
            /** @var ItemComparison $item */
            $verdicts[(string) ($item->original?->name ?? $item->demo?->name)] = ComparisonScorer::itemVerdict($item);
        }

        return $verdicts;
    }

    /**
     * The source order and a demo of it that differ only in their lines.
     *
     * @param list<array<string, mixed>> $originalLines
     * @param list<array<string, mixed>> $demoLines
     */
    private function lines(array $originalLines, array $demoLines): OrderComparison
    {
        $original = $this->sourceDocument();
        $original['items'] = $originalLines;
        $demo = $this->sourceDocument();
        $demo['id'] = 16630999;
        $demo['items'] = $demoLines;

        return $this->compareDocument((new OrderNormalizer())->normalize($original), $demo);
    }

    /**
     * One synthetic line, in the document shape both orders use.
     *
     * @param list<string> $options
     *
     * @return array<string, mixed>
     */
    private static function line(
        string $name,
        int $quantity = 1,
        string $price = '2.00000',
        array $options = [],
    ): array {
        return [
            'name' => $name,
            'quantity' => $quantity,
            'price' => $price,
            'total' => $price,
            'sn' => null,
            'options' => array_map(static fn(string $option): array => ['name' => $option], $options),
        ];
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
     * Customer 4/4 (the phone and the customer count are not shown, so not counted), Order 2/2 (order type and payment
     * method; the status is not counted, and a cash order has no card details), Delivery address 4/4
     * (Street 2, City, State, Postal code — the street line cannot be compared and Destination is only
     * on the demo, so neither is charged) — each 100%.
     * Wonton Soup: Product 1 + Additions 1 earned of 4 weighted (Quantity ×2 wrong; unit price and
     * product code are not scored), so 2/4. Spring Roll agreed on its 3 weighted units, so 1. The line
     * mean is (1/2 + 1) / 2 = 75%.
     * Overall = (100·40 + 100·40 + 100·10 + 75·90) / 180 = 87.5%.
     */
    public function testOneWrongQuantityOnOneLine(): void
    {
        $score = $this->score($this->source(), '16758551-16630651.json');

        self::assertSame(100, $this->section($score, 'Customer')->displayPercent());
        self::assertSame(100, $this->section($score, 'Order')->displayPercent());
        self::assertSame(100, $this->section($score, 'Delivery address')->displayPercent());
        self::assertSame(75, $this->section($score, 'Order items')->displayPercent());

        self::assertSame(4, $this->section($score, 'Customer')->scoredUnits);
        self::assertSame(2, $this->section($score, 'Order')->scoredUnits);
        self::assertSame(4, $this->section($score, 'Delivery address')->scoredUnits);

        self::assertEqualsWithDelta(87.5, $score->overall ?? 0.0, 0.0001);
        self::assertSame(88, $score->displayPercent());
    }

    /**
     * One missing modifier on one of two lines.
     *
     * Wonton Soup loses only its Additions unit (1 of 4 weighted), so 3/4; `Options` is the union of
     * Additions and Combinations and is deliberately not weighed, or one forgotten modifier would be
     * charged twice. Lines: (3/4 + 1) / 2 = 87.5%.
     * Overall = (4000 + 4000 + 1000 + 87.5·90) / 180 = 93.75%.
     */
    public function testOneMissingModifierCostsOneUnitNotThree(): void
    {
        $score = $this->score($this->source(), '16758551-16630661.json');

        self::assertSame(88, $this->section($score, 'Order items')->displayPercent());
        self::assertEqualsWithDelta(93.75, $score->overall ?? 0.0, 0.0001);
        self::assertSame(94, $score->displayPercent());
    }

    /**
     * A line missed, a line invented, and a wrong grand total that costs nothing.
     *
     * Items: Wonton Soup 1, Spring Roll missing 0, Egg Roll extra 0 → mean 1/3 = 33.333…%.
     * Totals are shown for reference and not scored, so the wrong total moves nothing.
     * Overall = (4000 + 4000 + 1000 + 33.333…·90) / 180 = 66.666…%.
     */
    public function testAMissedLineAnInventedLineAndAWrongTotal(): void
    {
        $score = $this->score($this->source(), '16758551-16630671.json');

        self::assertSame(33, $this->section($score, 'Order items')->displayPercent());
        self::assertFalse($this->section($score, 'Totals')->isScored());
        self::assertSame(1, $this->section($score, 'Totals')->different, 'the difference is still reported');
        self::assertEqualsWithDelta(66.666667, $score->overall ?? 0.0, 0.0001);
        self::assertSame(67, $score->displayPercent());
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

        // Totals are not scored, so they carry no share; the delivery address took theirs.
        self::assertSame(
            ['Customer', 'Order', 'Delivery address', 'Order items'],
            array_keys($weighting),
        );

        // Exactly a hundred, not approximately: the full-precision shares sum without residue.
        self::assertSame(100.0, array_sum($weighting));

        // Full precision, so the stored value is not the printed one.
        self::assertEqualsWithDelta(22.222222222222, $weighting['Customer'], 0.000000001);
        self::assertEqualsWithDelta(5.5555555555556, $weighting['Delivery address'], 0.000000001);
        self::assertSame(50.0, $weighting['Order items']);

        // The proportions the old tally expressed, unchanged: items are nine times the smallest share,
        // and customer and order are four times it each.
        self::assertEqualsWithDelta(9.0, $weighting['Order items'] / $weighting['Delivery address'], 0.000000001);
        self::assertEqualsWithDelta(4.0, $weighting['Customer'] / $weighting['Delivery address'], 0.000000001);

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
        self::assertSame(100, $this->section($score, 'Delivery address')->displayPercent());
        self::assertEqualsWithDelta(50.0, $score->overall ?? 0.0, 0.0001);
    }

    // --------------------------------------------------------------- what is counted, and what is not

    /**
     * Delivery address is scored on the rows both orders can state.
     *
     * Six rows show. Street 2, City, State and Postal code agree — four scored units. The street line
     * is not comparable (the original's is never stored) and Destination is only on the demo, so both
     * are shown and neither is charged.
     */
    public function testDeliveryAddressIsScoredOnTheRowsBothOrdersCanState(): void
    {
        $address = $this->section($this->score($this->source(), '16758551-16630651.json'), 'Delivery address');

        self::assertSame(100, $address->displayPercent());
        self::assertFalse($address->informationalOnly);
        self::assertSame(4, $address->scoredUnits);
        self::assertSame(6, $address->visibleUnits);
        self::assertSame(1, $address->notApplicable, 'the street line');
        self::assertSame(1, $address->extra, 'Destination, only on the demo');
        self::assertEqualsWithDelta(5.5555555555556, $address->weight, 0.000000001);
    }

    /**
     * A field both documents left empty is not a unit.
     *
     * A cash order on both sides has no card, expiry or billing ZIP: the page draws no row for them and
     * the score counts two units out of two visible — not five out of five. Scoring the silence would
     * pad every order with agreement nobody earned.
     */
    public function testFieldsBlankOnBothSidesAreNeitherShownNorScored(): void
    {
        $order = $this->section($this->score($this->source(), '16758551-16630651.json'), 'Order');

        self::assertSame(2, $order->visibleUnits);
        self::assertSame(2, $order->scoredUnits);
        self::assertSame(3, $order->hiddenAbsences);
    }

    /** Totals are shown for reference only: no percentage, no share of the whole, no coverage. */
    public function testTotalsAreShownButNeverScored(): void
    {
        $totals = $this->section($this->score($this->source(), '16758551-16630671.json'), 'Totals');

        self::assertFalse($totals->isScored());
        self::assertTrue($totals->informationalOnly);
        self::assertSame(0.0, $totals->weight);
        self::assertSame(0, $totals->scoredUnits);
        self::assertArrayNotHasKey('Totals', ComparisonScorer::weighting());
    }

    // ------------------------------------------------------------ hidden, informational and combined rows

    /**
     * What the page does not show is not scored: a different phone number, customer count, order status
     * or product code moves nothing.
     */
    public function testHiddenFieldsAreNotScored(): void
    {
        $raw = $this->demoDocument('16758551-16630651.json');
        $raw['status'] = 'cancelled';
        $raw['items'][0]['quantity'] = 2;
        $raw['items'][0]['sn'] = 'ZZ9';
        $data = json_decode((string) $raw['data'], true);
        self::assertIsArray($data);
        $data['reservation']['customer_count'] = 7;
        $data['reservation']['phone'] = '15550000000';
        $raw['data'] = (string) json_encode($data);

        $score = $this->scoreDocument($this->source(), $raw);

        self::assertSame(100, $this->section($score, 'Customer')->displayPercent());
        self::assertSame(100, $this->section($score, 'Order')->displayPercent());
        self::assertSame(100, $this->section($score, 'Order items')->displayPercent());
        self::assertSame(100, $score->displayPercent());

        self::assertSame(['Phone', 'Customer count'], ComparisonScorer::HIDDEN_FIELDS['Customer']);
        self::assertSame(['Status'], ComparisonScorer::HIDDEN_FIELDS['Order']);
        self::assertSame(['Product SN'], ComparisonScorer::HIDDEN_ITEM_FIELDS);
    }

    /**
     * A different unit price is neither a match nor a mismatch.
     *
     * The comparator still reports the price as different — it is — but the line scores in full and is
     * headed as the match it scored. A matching price would have earned nothing either.
     */
    public function testUnitPriceIsNeitherAMatchNorAMismatch(): void
    {
        $raw = $this->demoDocument('16758551-16630651.json');
        $raw['items'][0]['quantity'] = 2;
        $raw['items'][0]['price'] = '9.99000';

        $comparison = $this->compareDocument($this->source(), $raw);
        $wonton = $comparison->items[0];

        self::assertSame(ComparisonStatus::Different, $wonton->status, 'the comparator still sees the price');
        self::assertSame(ComparisonStatus::Match, ComparisonScorer::itemVerdict($wonton));

        $score = (new ComparisonScorer())->score($comparison);

        self::assertSame(100, $this->section($score, 'Order items')->displayPercent());
        self::assertSame(2, $this->section($score, 'Order items')->matched);
        self::assertSame(0, $this->section($score, 'Order items')->different);
    }

    /**
     * Changing only unit prices changes no score at all.
     *
     * The reported order, scored twice: once as it is (two lines priced differently from the original)
     * and once with every demo price replaced — some now matching the original, the rest far off. Every
     * section's percentage, matched, different, missing and scored counts, every line's verdict and the
     * overall are identical. A price is neither a match nor a mismatch, and is in no denominator.
     */
    public function testChangingOnlyUnitPricesChangesNoScore(): void
    {
        $asStored = $this->regression();

        $repriced = $this->regression(static function (array $demo): array {
            foreach ($demo['items'] as $index => $item) {
                // Even lines take the original's price, odd lines an absurd one.
                $demo['items'][$index]['price'] = $index % 2 === 0 ? '11.55000' : '999.99000';
            }

            return $demo;
        });

        $before = (new ComparisonScorer())->score($asStored);
        $after = (new ComparisonScorer())->score($repriced);

        self::assertSame($before->overall, $after->overall);
        self::assertSame($this->fingerprint($before), $this->fingerprint($after));
        self::assertEquals($this->verdictsByName($asStored), $this->verdictsByName($repriced));

        // And the prices really did change, so the test is not comparing a document with itself.
        self::assertNotSame(
            array_map(static fn(ItemComparison $i): ?string => $i->demo?->unitPrice, $asStored->items),
            array_map(static fn(ItemComparison $i): ?string => $i->demo?->unitPrice, $repriced->items),
        );
    }

    /** @return array<string, array<string, float|int|null>> */
    private function fingerprint(ComparisonScore $score): array
    {
        $out = [];

        foreach ($score->sections as $section) {
            $out[$section->title] = [
                'percent' => $section->percent,
                'matched' => $section->matched,
                'different' => $section->different,
                'missing' => $section->missing,
                'extra' => $section->extra,
                'scored' => $section->scoredUnits,
                'visible' => $section->visibleUnits,
            ];
        }

        return $out;
    }

    /**
     * Order details and payment details are one score.
     *
     * Order type ✓, Payment method ✓, Card ✓, Expiration date ✗ → 3 of 4 = 75%, under the one Order
     * section — there is no separate payment section to score.
     */
    public function testOrderAndPaymentDetailsShareOneScore(): void
    {
        $source = $this->paidByCard($this->source(), '4242', '12/2099');
        $demo = $this->paidByCard($this->demo('16758551-16630651.json'), '4242', '11/2099');

        $score = (new ComparisonScorer())->score(
            (new OrderComparator())->compare($source, $demo, OriginalItems::of(ItemAvailability::Synced, $source->items)),
        );

        $order = $this->section($score, 'Order');

        self::assertSame(4, $order->scoredUnits);
        self::assertSame(3, $order->matched);
        self::assertSame(1, $order->different);
        self::assertSame(75, $order->displayPercent());
        self::assertSame(
            ['Customer', 'Order', 'Delivery address', 'Totals', 'Order items'],
            array_map(static fn(SectionScore $s): string => $s->title, $score->sections),
        );
    }

    /**
     * A card detail only one order kept is shown, not counted.
     *
     * The demo was paid by card but its digits were not kept — "Card not recorded" — which is not the
     * trainee's mistake. A demo paid in cash is the payment method's mistake, already counted there.
     */
    public function testACardDetailOnlyOneOrderKeptIsNotCounted(): void
    {
        $source = $this->paidByCard($this->source(), '4242', '12/2099');
        $notRecorded = $this->paidByCard($this->demo('16758551-16630651.json'), null, null);

        $comparison = (new OrderComparator())->compare(
            $source,
            $notRecorded,
            OriginalItems::of(ItemAvailability::Synced, $source->items),
        );
        $order = $this->section((new ComparisonScorer())->score($comparison), 'Order');

        self::assertSame(NormalizedOrder::CARD_NOT_RECORDED, $comparison->payment[0]->demo);
        self::assertSame(ComparisonStatus::Uncomparable, $comparison->payment[0]->status);
        self::assertSame(2, $order->scoredUnits);
        self::assertSame(2, $order->notApplicable);
        self::assertSame(100, $order->displayPercent());

        // Paid in cash instead: the payment method is the one mistake, and it is charged once.
        $cash = (new OrderComparator())->compare(
            $source,
            $this->demo('16758551-16630651.json'),
            OriginalItems::of(ItemAvailability::Synced, $source->items),
        );
        $cashOrder = $this->section((new ComparisonScorer())->score($cash), 'Order');

        self::assertSame(1, $cashOrder->different);
        self::assertSame(2, $cashOrder->scoredUnits);
        self::assertSame(50, $cashOrder->displayPercent());
    }

    /**
     * A take-out original has no address to score, so the section leaves the overall.
     *
     * The demo's address values are shown — the trainee typed them — but only the demo has them, so
     * nothing is charged and nothing is earned: the section is not scored rather than 0% or 100%.
     */
    public function testATakeOutOriginalLeavesTheAddressOutOfTheOverall(): void
    {
        $raw = $this->sourceDocument();
        $raw['type'] = 'take-out';
        $data = json_decode((string) $raw['data'], true);
        self::assertIsArray($data);
        unset($data['shipping']);
        $raw['data'] = (string) json_encode($data);
        $takeOut = (new OrderNormalizer())->normalize($raw);

        $score = $this->score($takeOut, '16758551-16630651.json');
        $address = $this->section($score, 'Delivery address');

        self::assertFalse($address->isScored());
        self::assertSame(0, $address->scoredUnits);

        // Customer 100 at 40, Order 50 (the type differs) at 40, Items 75 at 90: (4000 + 2000 + 6750) / 170.
        self::assertEqualsWithDelta(75.0, $score->overall ?? 0.0, 0.0001);
    }

    /**
     * Destination counts only while the street line cannot be compared.
     *
     * Hand-built, because no stored order can reach the other branch today: `street1` is always
     * redacted, so Street is never comparable. If it ever is, Destination only repeats the rows above
     * it, and charging it as well would count one address mistake twice.
     */
    public function testDestinationCountsOnlyWhileTheStreetCannotBeCompared(): void
    {
        $order = $this->source();
        $address = static fn(ComparisonStatus $street): OrderComparison => new OrderComparison(
            $order,
            $order,
            [new ComparisonSection('Delivery address', [
                new FieldComparison('Street', '1 Main St', '1 Main St', $street),
                new FieldComparison('City', 'Freeport', 'Freeport', ComparisonStatus::Match),
                new FieldComparison('Destination', '1 Main St, Freeport', '2 Main St, Freeport', ComparisonStatus::Different),
            ])],
            [],
            ItemAvailability::AuthoritativeEmpty,
        );

        $redacted = $this->section((new ComparisonScorer())->score($address(ComparisonStatus::Uncomparable)), 'Delivery address');
        self::assertSame(2, $redacted->scoredUnits, 'City and Destination');
        self::assertSame(50, $redacted->displayPercent());

        $comparable = $this->section((new ComparisonScorer())->score($address(ComparisonStatus::Match)), 'Delivery address');
        self::assertSame(2, $comparable->scoredUnits, 'Street and City; Destination shown, not counted');
        self::assertSame(1, $comparable->notApplicable);
        self::assertSame(100, $comparable->displayPercent());
    }

    // --------------------------------------------------------------------- the reported order, end to end

    /**
     * Order #15275271 against demo #16635331 — the order on the reported page.
     *
     * First scoring: Customer 2/2, Order 3/3, Totals 33%, Items 58%, overall 75%. Then, with the phone
     * still scored: Customer 1/1, overall 84.29%.
     *
     * Now:
     *  - Customer: nothing shown — the phone and the customer count are not scored and the original
     *    holds no name, email or note — so the section leaves the overall.
     *  - Order 4/4 (order type, payment method, card, expiration date; no billing ZIP on either) — 100%.
     *  - Delivery address 3/3 (City, Postal code, Destination agree; State is only on the demo and the
     *    street line is not comparable) — 100%.
     *  - Items, seven lines: Fried Chicken Wings — Product 1 + Quantity 2 + Combinations 1 earned of 5
     *    (one addition differs) = 0.8; Shrimp w. Lobster Sauce and Shrimp Egg Roll differ only in
     *    unit price = 1 each; Homemade Ice Tea and Crispy Noodle = 1 each; Egg Foo Young Sauce missed
     *    = 0; Clear Soup added = 0. Mean 4.8 / 7 = 68.571…%.
     *  - Totals differ (subtotal, shipping, tax, total) and are not scored.
     *
     * Overall = (100·40 + 100·10 + 68.571…·90) / 140 = 79.795…%.
     */
    public function testTheReportedOrderScoresAsSpecified(): void
    {
        $fixture = $this->regression();
        $score = (new ComparisonScorer())->score($fixture);

        self::assertNull($this->section($score, 'Customer')->percent);
        self::assertSame(0, $this->section($score, 'Customer')->visibleUnits, 'nothing left to show');
        self::assertSame(100, $this->section($score, 'Order')->displayPercent());
        self::assertSame(4, $this->section($score, 'Order')->scoredUnits);
        self::assertSame(100, $this->section($score, 'Delivery address')->displayPercent());
        self::assertSame(3, $this->section($score, 'Delivery address')->scoredUnits);
        self::assertSame(1, $this->section($score, 'Delivery address')->extra);
        self::assertFalse($this->section($score, 'Totals')->isScored());

        self::assertEqualsWithDelta(68.571429, $this->section($score, 'Order items')->percent ?? 0.0, 0.0001);
        self::assertSame(69, $this->section($score, 'Order items')->displayPercent());
        self::assertSame(7, $this->section($score, 'Order items')->scoredUnits);

        self::assertSame(5, $this->section($score, 'Order items')->matched + $this->section($score, 'Order items')->different);
        self::assertSame(1, $this->section($score, 'Order items')->missing);
        self::assertSame(1, $this->section($score, 'Order items')->extra);

        self::assertEqualsWithDelta(79.795918, $score->overall ?? 0.0, 0.0001);
        self::assertSame(80, $score->displayPercent());

        // Each line headed by what it scored: a price-only difference is a match.
        self::assertSame(
            [
                'Fried Chicken Wings (4)' => ComparisonStatus::Different,
                'Shrimp w. Lobster Sauce' => ComparisonStatus::Match,
                'Egg Foo Young Sauce' => ComparisonStatus::MissingInDemo,
                'Homemade Ice Tea' => ComparisonStatus::Match,
                'Shrimp Egg Roll (1)' => ComparisonStatus::Match,
                'Crispy Noodle (1)' => ComparisonStatus::Match,
                'Clear Soup' => ComparisonStatus::ExtraInDemo,
            ],
            $this->verdictsByName($fixture),
        );
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

    // ------------------------------------------------------- products: matched, missing, extra, wrong

    /** Scenario A: the same products on both orders. Every line matched, the section full. */
    public function testTheSameProductsScoreInFull(): void
    {
        $comparison = $this->lines(
            [self::line('Wonton Soup'), self::line('Spring Roll')],
            [self::line('Wonton Soup'), self::line('Spring Roll')],
        );
        $items = $this->section((new ComparisonScorer())->score($comparison), 'Order items');

        self::assertSame(100, $items->displayPercent());
        self::assertSame(2, $items->matched);
        self::assertSame(2, $items->scoredUnits);
        self::assertSame(
            ['Wonton Soup' => ComparisonStatus::Match, 'Spring Roll' => ComparisonStatus::Match],
            $this->verdictsByName($comparison),
        );
    }

    /**
     * Scenario B: an extra product. Two matched lines and one the original does not have — three units,
     * two earned: 66.7%. An extra product is in the denominator, so the section cannot reach 100%.
     */
    public function testAnExtraProductIsChargedOnce(): void
    {
        $comparison = $this->lines(
            [self::line('Wonton Soup'), self::line('Spring Roll')],
            [self::line('Wonton Soup'), self::line('Spring Roll'), self::line('Egg Roll')],
        );
        $score = (new ComparisonScorer())->score($comparison);
        $items = $this->section($score, 'Order items');

        self::assertEqualsWithDelta(200 / 3, $items->percent ?? 0.0, 0.000001);
        self::assertSame(67, $items->displayPercent());
        self::assertSame(1, $items->extra);
        self::assertSame(3, $items->scoredUnits);
        self::assertSame(ComparisonStatus::ExtraInDemo, $this->verdictsByName($comparison)['Egg Roll']);
        self::assertLessThan(100.0, $score->overall ?? 100.0);
    }

    /** Scenario C: a missing product. Three units, two earned: 66.7%. */
    public function testAMissingProductIsChargedOnce(): void
    {
        $comparison = $this->lines(
            [self::line('Wonton Soup'), self::line('Spring Roll'), self::line('Egg Roll')],
            [self::line('Wonton Soup'), self::line('Spring Roll')],
        );
        $items = $this->section((new ComparisonScorer())->score($comparison), 'Order items');

        self::assertSame(67, $items->displayPercent());
        self::assertSame(1, $items->missing);
        self::assertSame(3, $items->scoredUnits);
        self::assertSame(ComparisonStatus::MissingInDemo, $this->verdictsByName($comparison)['Egg Roll']);
    }

    /**
     * Scenario D: a wrong product in place of an ordered one.
     *
     * Lines pair only with the same product, so Egg Roll is never matched to the Spring Roll it replaced
     * and nothing of it is judged against Spring Roll's quantity or modifiers. The ordered product is
     * missing and the wrong one is extra — three units, each line charged once, one earned: 33.3%. No
     * line is charged twice and no placeholder row earns anything back.
     */
    public function testAWrongProductIsNeverPairedWithTheOneItReplaced(): void
    {
        $comparison = $this->lines(
            [self::line('Wonton Soup'), self::line('Spring Roll')],
            [self::line('Wonton Soup'), self::line('Egg Roll')],
        );
        $items = $this->section((new ComparisonScorer())->score($comparison), 'Order items');

        foreach ($comparison->items as $item) {
            /** @var ItemComparison $item */
            if ($item->original !== null && $item->demo !== null) {
                self::assertSame($item->original->name, $item->demo->name, 'only a product is paired with itself');
            }
        }

        self::assertSame(
            [
                'Wonton Soup' => ComparisonStatus::Match,
                'Spring Roll' => ComparisonStatus::MissingInDemo,
                'Egg Roll' => ComparisonStatus::ExtraInDemo,
            ],
            $this->verdictsByName($comparison),
        );
        self::assertSame(1, $items->matched);
        self::assertSame(1, $items->missing);
        self::assertSame(1, $items->extra);
        self::assertSame(3, $items->scoredUnits);
        self::assertEqualsWithDelta(100 / 3, $items->percent ?? 0.0, 0.000001);
    }

    /** Scenario E: the same product at $11.00 and at $15.00. The price is the menu's; nothing moves. */
    public function testAPriceChangeAloneChangesNoScore(): void
    {
        $scorer = new ComparisonScorer();
        $samePrice = $this->lines(
            [self::line('Wonton Soup', price: '11.00000')],
            [self::line('Wonton Soup', price: '11.00000')],
        );
        $newPrice = $this->lines(
            [self::line('Wonton Soup', price: '11.00000')],
            [self::line('Wonton Soup', price: '15.00000')],
        );

        self::assertSame($scorer->score($samePrice)->overall, $scorer->score($newPrice)->overall);
        self::assertSame(100, $this->section($scorer->score($newPrice), 'Order items')->displayPercent());
        self::assertSame(ComparisonStatus::Match, $this->verdictsByName($newPrice)['Wonton Soup']);
    }

    /**
     * Scenario F: two ordered, one entered. Product earned (1) of Product 1 + Quantity 2 — the line has
     * no modifiers or note to weigh — so 1/3, and the line is Different.
     */
    public function testAWrongQuantityIsCharged(): void
    {
        $comparison = $this->lines(
            [self::line('Wonton Soup', quantity: 2)],
            [self::line('Wonton Soup', quantity: 1)],
        );
        $items = $this->section((new ComparisonScorer())->score($comparison), 'Order items');

        self::assertEqualsWithDelta(100 / 3, $items->percent ?? 0.0, 0.000001);
        self::assertSame(1, $items->different);
        self::assertSame(ComparisonStatus::Different, $this->verdictsByName($comparison)['Wonton Soup']);
    }

    /**
     * The same product twice: paired by what each line carries, not by position, and a second line the
     * demo left out is one missing unit.
     */
    public function testTheSameProductTwiceIsPairedByItsDetails(): void
    {
        $scorer = new ComparisonScorer();
        $swapped = $this->lines(
            [self::line('Wonton Soup', options: ['Extra noodles']), self::line('Wonton Soup')],
            [self::line('Wonton Soup'), self::line('Wonton Soup', options: ['Extra noodles'])],
        );
        $swappedItems = $this->section($scorer->score($swapped), 'Order items');

        self::assertSame(2, $swappedItems->matched);
        self::assertSame(100, $swappedItems->displayPercent());

        $oneShort = $this->section(
            $scorer->score($this->lines(
                [self::line('Wonton Soup'), self::line('Wonton Soup')],
                [self::line('Wonton Soup')],
            )),
            'Order items',
        );

        self::assertSame(1, $oneShort->matched);
        self::assertSame(1, $oneShort->missing);
        self::assertSame(50, $oneShort->displayPercent());
    }

    /**
     * A customisation left off: Product 1 + Quantity 2 earned of 4 (Additions missed) — 75%, the line
     * Different. Charged once: Options repeats the same modifier and is not weighed.
     */
    public function testAMissingCustomisationIsChargedOnTheLine(): void
    {
        $comparison = $this->lines(
            [self::line('Wonton Soup', options: ['Extra noodles'])],
            [self::line('Wonton Soup')],
        );
        $items = $this->section((new ComparisonScorer())->score($comparison), 'Order items');

        self::assertSame(75, $items->displayPercent());
        self::assertSame(ComparisonStatus::Different, $this->verdictsByName($comparison)['Wonton Soup']);
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
