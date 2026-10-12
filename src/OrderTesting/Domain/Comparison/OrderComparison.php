<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

/**
 * The whole result of comparing one demo order against its source order.
 *
 * **This is the object a future scoring pass reads.** It holds every fact a score could be computed
 * from — each field's two values and its status, each item pairing and its status — and no judgement at
 * all. Adding scoring is therefore adding a reader of this, not a second traversal of two payloads and
 * not a parser of the page.
 *
 * `$original` is nullable and that is a supported state, not an error: a demo order is imported from a
 * file and is never withheld because the orders mirror has not been synced for that date range. The page
 * says so on the left and shows the demo in full on the right.
 *
 * `$itemAvailability` is the same idea one level down. The original order can be mirrored while its
 * *line items* are not, because the items come from a second endpoint and a separate sync. A reader
 * must not infer "this order had no items" from an empty item list — it has to ask this.
 */
final readonly class OrderComparison
{
    /**
     * @param list<ComparisonSection> $sections
     * @param list<ItemComparison>    $items
     * @param list<NormalizedItem>    $removedItems lines the provider marked removed, never "missing"
     */
    public function __construct(
        public ?NormalizedOrder $original,
        public NormalizedOrder $demo,
        public array $sections,
        public array $items,
        public ItemAvailability $itemAvailability = ItemAvailability::NeverRequested,
        /** When the original items being shown were last successfully fetched. */
        public ?string $itemsSyncedAt = null,
        public array $removedItems = [],
    ) {}

    public function hasOriginal(): bool
    {
        return $this->original !== null;
    }

    /**
     * Whether the item lists were actually compared.
     *
     * False means every item row is {@see ComparisonStatus::Uncomparable} — either the order is not
     * mirrored, or its items have not been fetched. It does **not** mean the demo order is wrong.
     */
    public function itemsWereCompared(): bool
    {
        return $this->original !== null && $this->itemAvailability->isComparable();
    }

    /** The sentence the page shows above the item table, or '' when there is nothing to explain. */
    public function itemNote(): string
    {
        if ($this->original === null) {
            return 'The original order is not in the local mirror, so its items cannot be compared. '
                . 'Synchronise the store and date range that contains it.';
        }

        return $this->itemAvailability->note();
    }
}
