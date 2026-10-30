<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Comparison;

use App\OrderTesting\Domain\Comparison\ComparisonScore;
use App\OrderTesting\Domain\Comparison\ComparisonSection;
use App\OrderTesting\Domain\Comparison\ComparisonStatus;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemAvailability;
use App\OrderTesting\Domain\Comparison\ItemComparison;
use App\OrderTesting\Domain\Comparison\OrderComparison;
use App\OrderTesting\Domain\Comparison\SectionScore;

use function array_sum;
use function count;
use function in_array;
use function trim;

/**
 * How closely a demo order reproduced its original, scored from the comparison the page already built.
 *
 * ## It decides nothing about equality
 *
 * Every "did these two values agree" answer comes from {@see ComparisonRules}, through the statuses
 * {@see OrderComparator} already produced. This class only counts them and weighs them. That is the
 * point: the page and the score can never disagree about a field, because only one of them has an
 * opinion about fields.
 *
 * ## Scored units are the ones the reader can see
 *
 * The comparison carries six customer fields; a take-out order shows three, because the template drops
 * a row that is blank on both sides. Scoring follows the same rule, so a reader can add up what is on
 * screen and arrive at the number beside it. A field nobody filled in on either side says nothing
 * about the person who typed the demo, and counting it as a match would pad every score with silence.
 *
 * ## Extra-in-demo is not a mistake on a header field
 *
 * Measured across all 396 mirrored originals in this database: the provider records a phone on 100% of
 * them, a customer count on 88%, and a first name on 1.5%. So "the demo has a name and the original
 * does not" is overwhelmingly a gap in the source, not an invention by the trainee, and this scorer
 * shows it without charging for it. **Items are different and are charged for**: when the item list is
 * comparable the provider has stated its lines and their modifiers, so an extra line — or an extra
 * addition on a matched line — is a real discrepancy.
 *
 * ## What the comparison page scores, section by section
 *
 *  - **Customer details** — the customer fields shown. `Customer count` is not shown and not scored.
 *  - **Order details** — Order type and Payment method, and the card details under them (card, expiry,
 *    billing ZIP), as **one** score. `Status` is not shown and not scored. A card detail counts only
 *    when both orders kept it; see `OrderComparator::payment()`.
 *  - **Delivery address** — the address fields shown; see {@see addressFields()} for Street and
 *    Destination.
 *  - **Order items** — per line: Product, Quantity (double), Additions, Combinations and instructions.
 *    Unit price is shown and never scored; Product SN is not shown and not scored.
 *  - **Totals** — shown for reference, never scored.
 *
 * The rows the page leaves out are named once, in {@see HIDDEN_FIELDS} and {@see HIDDEN_ITEM_FIELDS},
 * and the template reads them from here — so a field cannot be hidden from the reader and still move
 * the number, or the other way round.
 *
 * ## Nothing here is persisted
 *
 * A score is derived from two documents that can both change. Storing one would mean storing something
 * that goes stale the moment items resync, so it is computed per render, from an object the page holds
 * anyway: no query, no table, no migration.
 */
final readonly class ComparisonScorer
{
    public const CUSTOMER = 'Customer';
    public const ORDER = 'Order';
    public const ADDRESS = 'Delivery address';
    public const ITEMS = 'Order items';
    public const TOTALS = 'Totals';

    /**
     * Section fields the comparison page does not show, and therefore never scores.
     *
     * Still compared, and still printed on the receipt page, which reads the comparison directly.
     */
    public const HIDDEN_FIELDS = [
        self::CUSTOMER => ['Customer count'],
        self::ORDER => ['Status'],
    ];

    /** Line-item fields the comparison page does not show, and therefore never scores. */
    public const HIDDEN_ITEM_FIELDS = ['Product SN'];

    /**
     * How much each section matters relative to the others. Not percentages, and never shown.
     *
     * Order items carry 90 of the 180, so an order whose every line is wrong cannot exceed 50% however
     * perfect its header is — half the measurement is what was actually ordered. That ceiling is the
     * stated intent of this weighting rather than a side effect of it, and a test holds it.
     *
     * `Delivery address` took the 10 that `Totals` carried when Totals stopped being scored, so every
     * other section's share — and the 50% ceiling above — is exactly what it was. A take-out order has
     * no address to score; its section then leaves the denominator rather than scoring zero.
     *
     * `Totals` is absent: it is shown for reference and never scored.
     */
    private const RELATIVE_SECTION_WEIGHTS = [
        self::CUSTOMER => 40,
        self::ORDER => 40,
        self::ADDRESS => 10,
        self::ITEMS => 90,
    ];

    /**
     * What a matched line's agreement is made of.
     *
     * `Options` is deliberately absent: it is the union of Additions and Combinations, so scoring all
     * three charged one wrong modifier three times — measured on a live pair, a single modifier
     * mistake produced Options different, Additions missing and Combinations different.
     *
     * `Unit price` is absent too: it is shown, and a different price is pointed out, but the price is
     * the menu's and not something the trainee chose — so it neither earns nor costs anything.
     * `Product SN` is not shown at all ({@see HIDDEN_ITEM_FIELDS}).
     */
    private const FACET_WEIGHTS = [
        'Product' => 1,
        'Quantity' => 2,
        'Additions' => 1,
        'Combinations' => 1,
        'Item instructions' => 1,
    ];

    /**
     * The same weighting, out of a hundred.
     *
     * A weighted mean does not care what its weights sum to — scaling every one of them by the same
     * factor cannot move the result — so this is a change of units and not of arithmetic. It is made
     * because 22.22% of the score says something to a reader that "40" does not, and the figure a
     * reader checks should be in the units they were told about.
     *
     * Derived, never typed out: writing 22.22 into the table would be a rounded number doing a full
     * precision number's job, and four of them would no longer total a hundred.
     *
     * @return array<string, float> section title => its share of the whole, summing to 100
     */
    public static function weighting(): array
    {
        $total = array_sum(self::RELATIVE_SECTION_WEIGHTS);
        $shares = [];

        foreach (self::RELATIVE_SECTION_WEIGHTS as $title => $weight) {
            $shares[$title] = $weight / $total * 100;
        }

        return $shares;
    }

    public function score(OrderComparison $comparison): ComparisonScore
    {
        $sections = [];

        foreach ($comparison->sections as $section) {
            $fields = self::shownFields($section);

            // Order details and the payment details printed under them are one score: one badge, one
            // denominator, nothing counted twice.
            if ($section->title === self::ORDER) {
                $fields = [...$fields, ...$comparison->payment];
            }

            if ($section->title === self::ADDRESS) {
                $fields = self::addressFields($fields);
            }

            $sections[] = $this->fieldSection($section->title, $fields);
        }

        $sections[] = $this->itemSection($comparison);

        return new ComparisonScore(
            $this->overall($sections, $comparison),
            $this->unavailableReason($comparison),
            $sections,
            self::weighting(),
        );
    }

    /**
     * The fields of a section the comparison page shows, before blank rows are dropped.
     *
     * The template asks the same question of the same constant, so the page and the score agree about
     * which rows exist.
     *
     * @return list<FieldComparison>
     */
    public static function shownFields(ComparisonSection $section): array
    {
        $hidden = self::HIDDEN_FIELDS[$section->title] ?? [];
        $shown = [];

        foreach ($section->fields as $field) {
            if (!in_array($field->label, $hidden, true)) {
                $shown[] = $field;
            }
        }

        return $shown;
    }

    /**
     * What a line item's verdict is, counting only what is scored.
     *
     * The comparator calls a pair `Different` when any field differs — a price, a product code. The
     * page heads each line with this instead, so a line that differs only in what is not scored reads
     * as the match it scored, and a "Different" heading always means the score was charged for it.
     * A line on one side only keeps the comparator's verdict.
     */
    public static function itemVerdict(ItemComparison $item): ComparisonStatus
    {
        if ($item->status !== ComparisonStatus::Match && $item->status !== ComparisonStatus::Different) {
            return $item->status;
        }

        foreach ($item->fields as $field) {
            if (
                !isset(self::FACET_WEIGHTS[$field->label])
                || $field->status === ComparisonStatus::Uncomparable
                || self::bothBlank($field)
            ) {
                continue;
            }

            if ($field->status !== ComparisonStatus::Match) {
                return ComparisonStatus::Different;
            }
        }

        return ComparisonStatus::Match;
    }

    /**
     * The delivery address's scorable rows.
     *
     * **Street** is scored when it can be compared. Today it cannot: `street1` is on the payment-secret
     * list, so the original order's street line is redacted, and the comparator reports the row as not
     * comparable — shown, never charged.
     *
     * **Destination** is the whole address as Order58 formats it — street, city, state and postcode on
     * one line. It is scored only while Street cannot be compared, because it is then the one place the
     * street line can be checked at all. If Street ever becomes comparable, Destination only repeats
     * the rows above it and is shown without being scored, so an address mistake is not charged twice.
     *
     * The other rows follow the header rule: a value both sides hold is compared, a value the demo
     * missed is charged, and a value only the demo holds is shown but not charged — which is also why a
     * take-out original, holding no address, scores nothing here and leaves the overall untouched.
     *
     * @param list<FieldComparison> $fields
     *
     * @return list<FieldComparison>
     */
    private static function addressFields(array $fields): array
    {
        $streetIsScored = false;

        foreach ($fields as $field) {
            if (
                $field->label === 'Street'
                && $field->status !== ComparisonStatus::Uncomparable
                && !self::bothBlank($field)
            ) {
                $streetIsScored = true;
            }
        }

        if (!$streetIsScored) {
            return $fields;
        }

        $kept = [];

        foreach ($fields as $field) {
            // Shown, and counted as shown, but not as a scored unit: see above.
            $kept[] = $field->label === 'Destination'
                ? new FieldComparison($field->label, $field->original, $field->demo, ComparisonStatus::Uncomparable)
                : $field;
        }

        return $kept;
    }

    /**
     * One of the header sections: customer, order and payment, delivery address, totals.
     *
     * Visibility first, status second. A row the page does not draw is not a unit, whatever status the
     * comparator gave it — that is what keeps the arithmetic addable from the screen.
     *
     * @param list<FieldComparison> $fields the rows the page shows for this section
     */
    private function fieldSection(string $title, array $fields): SectionScore
    {
        $informational = $title === self::TOTALS;

        $matched = $different = $missing = $extra = $notApplicable = 0;
        $hidden = $visible = $scored = 0;

        foreach ($fields as $field) {
            if (self::bothBlank($field)) {
                $hidden++;

                continue;
            }

            $visible++;

            switch ($field->status) {
                case ComparisonStatus::Match:
                    $matched++;
                    $scored++;

                    break;
                case ComparisonStatus::Different:
                    $different++;
                    $scored++;

                    break;
                case ComparisonStatus::MissingInDemo:
                    $missing++;
                    $scored++;

                    break;
                case ComparisonStatus::ExtraInDemo:
                    // Shown, counted, not charged — see the class docblock.
                    $extra++;

                    break;
                case ComparisonStatus::Uncomparable:
                    $notApplicable++;

                    break;
            }
        }

        return new SectionScore(
            title: $title,
            percent: $informational || $scored === 0 ? null : $matched / $scored * 100,
            matched: $matched,
            different: $different,
            missing: $missing,
            extra: $extra,
            notApplicable: $notApplicable,
            // An informational section reports no coverage either: "4 of 6 fields" beside nothing
            // scored would be describing a measurement nobody took.
            scoredUnits: $informational ? 0 : $scored,
            visibleUnits: $visible,
            hiddenAbsences: $hidden,
            extraIsScored: false,
            unit: 'field',
            informationalOnly: $informational,
            weight: self::weighting()[$title] ?? 0.0,
        );
    }

    /**
     * The item list, scored one unit per line rather than one per field.
     *
     * A missing line already carries seven child fields of its own, one of which can be a match
     * because neither side filled it in. Counting those would charge one mistake seven times and hand
     * back credit for the silence, so a missing or extra line is exactly one unit, worth nothing, and
     * the facets only decide how much of a *matched* line was reproduced.
     */
    private function itemSection(OrderComparison $comparison): SectionScore
    {
        $matched = $different = $missing = $extra = $notApplicable = 0;
        $units = [];

        foreach ($comparison->items as $item) {
            // The scored verdict, so the counts agree with the headings the page draws.
            switch (self::itemVerdict($item)) {
                case ComparisonStatus::Match:
                    $matched++;
                    $units[] = $this->facetScore($item);

                    break;
                case ComparisonStatus::Different:
                    $different++;
                    $units[] = $this->facetScore($item);

                    break;
                case ComparisonStatus::MissingInDemo:
                    $missing++;
                    $units[] = 0.0;

                    break;
                case ComparisonStatus::ExtraInDemo:
                    $extra++;
                    $units[] = 0.0;

                    break;
                case ComparisonStatus::Uncomparable:
                    $notApplicable++;

                    break;
            }
        }

        $percent = null;

        if ($comparison->itemsWereCompared()) {
            // Both lists empty, and the provider SAID there are no lines. That is agreement on a
            // stated fact rather than two silences, which is why it earns full credit where an
            // unfilled field does not. Any other empty case is not comparable and scores nothing.
            $percent = $units === []
                ? ($comparison->itemAvailability === ItemAvailability::AuthoritativeEmpty ? 100.0 : null)
                : array_sum($units) / count($units) * 100;
        }

        return new SectionScore(
            title: self::ITEMS,
            percent: $percent,
            matched: $matched,
            different: $different,
            missing: $missing,
            extra: $extra,
            notApplicable: $notApplicable,
            scoredUnits: count($units),
            visibleUnits: count($comparison->items),
            hiddenAbsences: 0,
            extraIsScored: true,
            unit: 'item',
            weight: self::weighting()[self::ITEMS],
        );
    }

    /** How much of one paired line was reproduced, in 0..1. */
    private function facetScore(ItemComparison $item): float
    {
        $weight = 0.0;
        $earned = 0.0;

        foreach ($item->fields as $field) {
            $facetWeight = self::FACET_WEIGHTS[$field->label] ?? null;

            if ($facetWeight === null || $field->status === ComparisonStatus::Uncomparable) {
                continue;
            }

            if (self::bothBlank($field)) {
                continue;
            }

            $weight += $facetWeight;

            if ($field->status === ComparisonStatus::Match) {
                $earned += $facetWeight;
            }
        }

        // A pair exists because its product matched, so this cannot be reached with nothing weighed.
        return $weight === 0.0 ? 1.0 : $earned / $weight;
    }

    /**
     * The weighted average, over the sections that both carry weight and could be scored.
     *
     * Section percentages are already 0–100, so this is their weighted mean and nothing is multiplied
     * by a hundred twice. A section that could not be scored leaves the denominator rather than
     * scoring zero: an absent measurement is not a failed one — which is also why the divisor is the
     * applicable weight rather than a flat 100: four sections share the whole, three share it too.
     *
     * @param list<SectionScore> $sections
     */
    private function overall(array $sections, OrderComparison $comparison): ?float
    {
        if (!$comparison->hasOriginal() || !$comparison->itemsWereCompared()) {
            return null;
        }

        $weighted = 0.0;
        $weights = 0.0;

        foreach ($sections as $section) {
            // The weight the section carries, not a second lookup: the page prints that one, so the
            // sum and the explanation beside it cannot drift apart.
            $weight = $section->weight;
            $percent = $section->percent;

            // `$percent === null` rather than `isScored()`: same question, but it narrows the type, so
            // the multiplication below is provably not on a null.
            if ($weight === 0.0 || $percent === null) {
                continue;
            }

            $weighted += $percent * $weight;
            $weights += $weight;
        }

        return $weights === 0.0 ? null : $weighted / $weights;
    }

    private function unavailableReason(OrderComparison $comparison): ?string
    {
        if (!$comparison->hasOriginal()) {
            return 'The original order is not in the local mirror, so there is nothing to compare against.';
        }

        if (!$comparison->itemsWereCompared()) {
            return $comparison->itemNote();
        }

        return null;
    }

    /** The template's own rule for whether a row is drawn at all. */
    private static function bothBlank(FieldComparison $field): bool
    {
        return ($field->original === null || trim($field->original) === '')
            && ($field->demo === null || trim($field->demo) === '');
    }
}
