<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

/**
 * One line of the original paired with one line of the demo, or either one alone.
 *
 * Pairing is by product name, never by a document id; see {@see NormalizedItem::matchKey()}.
 */
final readonly class ItemComparison
{
    /** @param list<FieldComparison> $fields quantity, unit price, total and options, each compared */
    public function __construct(
        public ?NormalizedItem $original,
        public ?NormalizedItem $demo,
        public ComparisonStatus $status,
        public array $fields = [],
    ) {}

    /** What to put in the row's heading, whichever side exists. */
    public function label(): string
    {
        return $this->original?->name ?? $this->demo?->name ?? 'Unnamed item';
    }
}
