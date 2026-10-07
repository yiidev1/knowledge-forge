<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Comparison;

use App\OrderTesting\Domain\Comparison\ComparisonSection;
use App\OrderTesting\Domain\Comparison\ComparisonStatus;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemComparison;
use App\OrderTesting\Domain\Comparison\NormalizedItem;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
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
 * ## Items are paired by name, never by id
 *
 * See {@see NormalizedItem::matchKey()}. The pairing is a single pass over the original's lines, each
 * claiming at most one unclaimed demo line with the same key; whatever is left on either side becomes
 * `MissingInDemo` or `ExtraInDemo`. Duplicate names therefore pair in document order, which is
 * deterministic and is the only property a later scoring pass needs from it.
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

    public function compare(?NormalizedOrder $original, NormalizedOrder $demo): OrderComparison
    {
        return new OrderComparison(
            $original,
            $demo,
            $this->sections($original, $demo),
            $this->items($original, $demo),
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

    /** @return list<ItemComparison> */
    private function items(?NormalizedOrder $original, NormalizedOrder $demo): array
    {
        if ($original === null) {
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

        // Paired by INDEX, not by value identity.
        //
        // The obvious implementation keeps the matched objects in a list and asks `in_array(…, true)`
        // what is left over. That is wrong for the case this feature exists to catch: two lines of the
        // same product with the same quantity and price are equal value objects, so the second one
        // would be reported as already claimed and silently vanish from the comparison. Marking the
        // position claimed cannot confuse two identical lines.
        //
        // O(n * m) over lists capped at 200 entries by the normalizer, which is nothing, and it keeps
        // the pairing in document order — the only property a later scoring pass needs from it.
        $claimed = [];
        $rows = [];

        foreach ($original->items as $item) {
            $key = $item->matchKey();
            $matchIndex = null;

            if ($key !== '') {
                foreach ($demo->items as $index => $candidate) {
                    if (!isset($claimed[$index]) && $candidate->matchKey() === $key) {
                        $matchIndex = $index;

                        break;
                    }
                }
            }

            if ($matchIndex === null) {
                $rows[] = new ItemComparison(
                    $item,
                    null,
                    ComparisonStatus::MissingInDemo,
                    $this->itemFields($item, null, true),
                );

                continue;
            }

            $claimed[$matchIndex] = true;
            $match = $demo->items[$matchIndex];
            $fields = $this->itemFields($item, $match, true);

            $differs = false;
            foreach ($fields as $field) {
                if ($field->status !== ComparisonStatus::Match) {
                    $differs = true;

                    break;
                }
            }

            $rows[] = new ItemComparison(
                $item,
                $match,
                $differs ? ComparisonStatus::Different : ComparisonStatus::Match,
                $fields,
            );
        }

        // Whatever no original line claimed is something the trainee added.
        foreach ($demo->items as $index => $item) {
            if (!isset($claimed[$index])) {
                $rows[] = new ItemComparison(
                    null,
                    $item,
                    ComparisonStatus::ExtraInDemo,
                    $this->itemFields(null, $item, true),
                );
            }
        }

        return $rows;
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
            ['Quantity', $this->asText($original?->quantity), $this->asText($demo?->quantity), $text],
            ['Unit price', $original?->unitPrice, $demo?->unitPrice, $money],
            ['Line total', $original?->total, $demo?->total, $money],
            ['Options', $original?->optionsLabel(), $demo?->optionsLabel(), $text],
            ['Item instructions', $original?->instructions, $demo?->instructions, $text],
            ['Product SN', $original?->sn, $demo?->sn, $text],
        ];

        $fields = [];

        foreach ($pairs as [$label, $left, $right, $equals]) {
            // An empty options label is "no options", not a value worth a row of its own.
            $left = $left === '' ? null : $left;
            $right = $right === '' ? null : $right;

            $fields[] = new FieldComparison(
                $label,
                $left,
                $right,
                $comparable
                    ? ComparisonRules::status($left, $right, $equals)
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
