<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Comparison;

use App\OrderTesting\Domain\Comparison\ComparisonSection;
use App\OrderTesting\Domain\Comparison\ComparisonStatus;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemComparison;
use App\OrderTesting\Domain\Comparison\NormalizedItem;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\OrderTesting\Domain\Comparison\OriginalItems;
use App\OrderTesting\Domain\Comparison\OrderComparison;
use App\Shared\Order58\PaymentSecretRedactor;

use function array_map;
use function in_array;

/**
 * Puts one demo order beside its source order and records what differs.
 *
 * ## Facts, not judgement
 *
 * Every output is a value, a value and a {@see ComparisonStatus}. Nothing here weights a field, counts
 * an error or produces a number — scoring is a later, separately specified piece of work, and this is
 * the layer it will read. Keeping the two apart is what makes the score changeable without re-deriving
 * the comparison, and the comparison reviewable without arguing about the score.
 *
 * ## A missing source order is a state, not a failure
 *
 * A demo order is imported from a file; the source order it was recreated from is mirrored from an API
 * over a date range somebody has to ask for. The second can legitimately be absent. When it is, every
 * field comes back `Uncomparable` and the demo side is still fully populated — the page says the mirror
 * does not have it, rather than hiding work a trainee really did.
 *
 * ## An itemless original is not an empty original
 *
 * The original order's lines do not live in its payload — that document has no line items at any depth,
 * which is why a second provider endpoint exists for them. They are fetched separately and carry a
 * synchronisation state, so an empty list means either "this order genuinely has none" or "nobody has
 * fetched them yet", and only {@see OriginalItems} knows which.
 *
 * Trusting an empty list was this comparison's one real defect: every line the trainee entered was
 * reported `ExtraInDemo`, which told them they had invented an order they had in fact recreated
 * correctly. So the item lists are compared only when {@see OriginalItems::isComparable()} says the
 * left-hand side is actually known, and are otherwise all `Uncomparable`.
 *
 * ## Items are paired by catalogue id or name, never by a document line id
 *
 * See {@see ItemMatcher}, which also decides how duplicate lines of one product pair up.
 */
final readonly class OrderComparator
{
    /**
     * Whether the stored payloads still hold a street line at all.
     *
     * Read from the redactor's own list rather than hard-coded, so removing `street1` from it one day
     * turns the street back into an ordinary compared field with no edit here.
     */
    private static function streetIsRedacted(): bool
    {
        return in_array('street1', PaymentSecretRedactor::PAYMENT_SECRETS, true);
    }

    public function __construct(private ItemMatcher $matcher = new ItemMatcher()) {}

    /**
     * @param OriginalItems|null $originalItems null is read as "never requested", which keeps every
     *                                          existing caller honest rather than silently comparing
     *                                          against an empty basket
     */
    public function compare(
        ?NormalizedOrder $original,
        NormalizedOrder $demo,
        ?OriginalItems $originalItems = null,
    ): OrderComparison {
        $items = $originalItems ?? OriginalItems::neverRequested();

        return new OrderComparison(
            $original,
            $demo,
            $this->sections($original, $demo),
            $this->items($original, $demo, $items),
            $items->availability,
            $items->syncedAt,
            $items->removed(),
        );
    }

    /** @return list<ComparisonSection> */
    private function sections(?NormalizedOrder $original, NormalizedOrder $demo): array
    {
        $text = ComparisonRules::textEquals(...);
        $money = ComparisonRules::moneyEquals(...);
        $phone = ComparisonRules::phoneEquals(...);

        $o = $original;

        return [
            new ComparisonSection('Customer', [
                $this->field('First name', $o?->customer->firstName, $demo->customer->firstName, $text, $o),
                $this->field('Last name', $o?->customer->lastName, $demo->customer->lastName, $text, $o),
                $this->field('Phone', $o?->customer->phone, $demo->customer->phone, $phone, $o),
                $this->field('Email', $o?->customer->email, $demo->customer->email, $text, $o),
                $this->field(
                    'Customer count',
                    $this->asText($o?->customer->customerCount),
                    $this->asText($demo->customer->customerCount),
                    $text,
                    $o,
                ),
                $this->field(
                    'Special instructions',
                    $o?->customer->instructions,
                    $demo->customer->instructions,
                    $text,
                    $o,
                ),
            ]),
            new ComparisonSection('Order', [
                $this->field('Order type', $o?->orderType, $demo->orderType, $text, $o),
                $this->field('Payment method', $o?->paymentMethod, $demo->paymentMethod, $text, $o),
                $this->field('Status', $o?->status, $demo->status, $text, $o),
            ]),
            new ComparisonSection('Delivery address', [
                // The street line is the one field that cannot be compared, and the reason is not the
                // trainee. `street1` is on the payment-secret list, so it is stripped from BOTH stored
                // payloads at every depth — including from the shipping block, where the same key name
                // is reused for the delivery address. The demo side keeps its own copy in a column
                // written before redaction; the mirrored source order has no such column, so there is
                // nothing on the left to compare against. Reported as not comparable rather than as the
                // trainee having added an address, which is what a plain comparison would have said.
                new FieldComparison(
                    'Street',
                    null,
                    $demo->address->street,
                    self::streetIsRedacted() ? ComparisonStatus::Uncomparable
                        : ComparisonRules::status($o?->address->street, $demo->address->street, $text),
                ),
                $this->field('Street 2', $o?->address->street2, $demo->address->street2, $text, $o),
                $this->field('City', $o?->address->city, $demo->address->city, $text, $o),
                $this->field('State', $o?->address->state, $demo->address->state, $text, $o),
                $this->field('Postal code', $o?->address->postalCode, $demo->address->postalCode, $text, $o),
                $this->field('Destination', $o?->address->destination, $demo->address->destination, $text, $o),
            ]),
            new ComparisonSection('Totals', [
                $this->field('Subtotal', $o?->totals->subtotal, $demo->totals->subtotal, $money, $o),
                $this->field('Shipping fee', $o?->totals->shippingFee, $demo->totals->shippingFee, $money, $o),
                $this->field('Tax', $o?->totals->tax, $demo->totals->tax, $money, $o),
                $this->field('Surcharge', $o?->totals->surcharge, $demo->totals->surcharge, $money, $o),
                $this->field('Tip', $o?->totals->tip, $demo->totals->tip, $money, $o),
                $this->field('Discount', $o?->totals->discount, $demo->totals->discount, $money, $o),
                $this->field('Total', $o?->totals->total, $demo->totals->total, $money, $o),
            ]),
        ];
    }

    /**
     * One field, or an uncomparable one when there is no source order at all.
     *
     * `$original` being null as a *value* and the whole source order being absent are different things,
     * which is why the order itself is passed in: without it, every field of an unmirrored order would
     * read `ExtraInDemo`, which says the trainee added something they did not add.
     *
     * @param callable(?string, ?string): bool $equals
     */
    private function field(
        string $label,
        ?string $original,
        ?string $demo,
        callable $equals,
        ?NormalizedOrder $sourceOrder,
    ): FieldComparison {
        $status = $sourceOrder === null
            ? ComparisonStatus::Uncomparable
            : ComparisonRules::status($original, $demo, $equals);

        return new FieldComparison($label, $original, $demo, $status);
    }

    /**
     * The item rows, or an honest refusal to compare them.
     *
     * Two separate reasons to refuse, and neither is the trainee's fault:
     *
     *  - the source order is not mirrored at all, so there is nothing on the left;
     *  - the order is mirrored but its lines have never been fetched, or the last fetch failed with
     *    nothing stored from before.
     *
     * In both cases every demo line is `Uncomparable` and stays fully visible. The alternative — the
     * behaviour this replaces — was to treat an unknown left-hand side as an empty one and report the
     * trainee's whole basket as invented.
     *
     * @return list<ItemComparison>
     */
    private function items(?NormalizedOrder $original, NormalizedOrder $demo, OriginalItems $items): array
    {
        if ($original === null || !$items->isComparable()) {
            return array_map(
                fn(NormalizedItem $item): ItemComparison => new ItemComparison(
                    null,
                    $item,
                    ComparisonStatus::Uncomparable,
                    $this->itemFields(null, $item, false),
                ),
                $demo->items,
            );
        }

        $rows = [];

        // `live()` excludes lines the provider marked removed: those are reported separately and must
        // never read as "missing in demo", because a trainee is right not to have entered them.
        foreach ($this->matcher->pair($items->live(), $demo->items) as $pair) {
            $rows[] = $this->row($pair['original'], $pair['demo']);
        }

        return $rows;
    }

    /** One paired row, or one side alone. */
    private function row(?NormalizedItem $original, ?NormalizedItem $demo): ItemComparison
    {
        if ($original === null) {
            // Nothing on the left claimed it, and the left-hand side is known. The trainee added it.
            return new ItemComparison(
                null,
                $demo,
                ComparisonStatus::ExtraInDemo,
                $this->itemFields(null, $demo, true),
            );
        }

        if ($demo === null) {
            return new ItemComparison(
                $original,
                null,
                ComparisonStatus::MissingInDemo,
                $this->itemFields($original, null, true),
            );
        }

        $fields = $this->itemFields($original, $demo, true);

        $differs = false;
        foreach ($fields as $field) {
            if ($field->status !== ComparisonStatus::Match) {
                $differs = true;

                break;
            }
        }

        return new ItemComparison(
            $original,
            $demo,
            $differs ? ComparisonStatus::Different : ComparisonStatus::Match,
            $fields,
        );
    }

    /**
     * The per-line fields, which is where a quantity or a modifier difference actually shows up.
     *
     * @return list<FieldComparison>
     */
    private function itemFields(?NormalizedItem $original, ?NormalizedItem $demo, bool $comparable): array
    {
        $text = ComparisonRules::textEquals(...);
        $money = ComparisonRules::moneyEquals(...);

        $pairs = [
            // The interpreted name, so `[F7]Fried Chicken Wings (4)` and `Fried Chicken Wings (4)`
            // agree. The row heading above still shows the provider's exact string.
            ['Product', $original?->productName ?? $original?->name, $demo?->productName ?? $demo?->name, $text],
            // Quantity through the decimal rule, so `"1"` and `"1.000"` are one quantity.
            ['Quantity', $original?->quantityLabel(), $demo?->quantityLabel(), $money],
            ['Unit price', $original?->unitPrice, $demo?->unitPrice, $money],
            // Modifiers get three rows, because an operator fixes the two kinds differently: an
            // addition is something asked for on top of the product ("extra hot sauce"), a combination
            // is a choice the product requires ("size: large"). `Options` stays as the whole set, so a
            // page that only reads one row still sees every difference.
            //
            // Each is **shown** as readable labels in document order and **compared** on the canonical
            // signature, which is sorted — a trainee who chose the right extras in a different order
            // has made no mistake, and comparing the document order reported one. Hence the two extra
            // columns on each pair.
            [
                'Options',
                $original?->optionsLabel(),
                $demo?->optionsLabel(),
                $text,
                $original?->modifierSignature,
                $demo?->modifierSignature,
            ],
            [
                'Additions',
                $original?->additionsLabel(),
                $demo?->additionsLabel(),
                $text,
                $original?->additionSignature,
                $demo?->additionSignature,
            ],
            [
                'Combinations',
                $original?->combinationsLabel(),
                $demo?->combinationsLabel(),
                $text,
                $original?->combinationSignature,
                $demo?->combinationSignature,
            ],
            ['Item instructions', $original?->instructions, $demo?->instructions, $text],
            ['Product SN', $original?->sn, $demo?->sn, $text],
        ];

        // `Line total` is deliberately absent: the provider's items carry no line total, so comparing
        // one would report a difference on every line of every order.

        $fields = [];

        foreach ($pairs as $pair) {
            [$label, $left, $right, $equals] = $pair;

            // A field may be compared on something other than what it displays. Where it is not, the
            // displayed values are the compared ones.
            $compareLeft = $pair[4] ?? $left;
            $compareRight = $pair[5] ?? $right;

            // An empty options label is "no options", not a value worth a row of its own.
            $left = $left === '' ? null : $left;
            $right = $right === '' ? null : $right;
            $compareLeft = $compareLeft === '' ? null : $compareLeft;
            $compareRight = $compareRight === '' ? null : $compareRight;

            $fields[] = new FieldComparison(
                $label,
                $left,
                $right,
                $comparable
                    ? ComparisonRules::status($compareLeft, $compareRight, $equals)
                    : ComparisonStatus::Uncomparable,
            );
        }

        return $fields;
    }

    private function asText(?int $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
