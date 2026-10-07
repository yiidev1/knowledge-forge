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
 */
final readonly class OrderComparison
{
    /**
     * @param list<ComparisonSection> $sections
     * @param list<ItemComparison>    $items
     */
    public function __construct(
        public ?NormalizedOrder $original,
        public NormalizedOrder $demo,
        public array $sections,
        public array $items,
    ) {}

    public function hasOriginal(): bool
    {
        return $this->original !== null;
    }
}
