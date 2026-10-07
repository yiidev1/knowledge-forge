<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

/** A titled group of fields, so the page's structure is decided here rather than in markup. */
final readonly class ComparisonSection
{
    /** @param list<FieldComparison> $fields */
    public function __construct(
        public string $title,
        public array $fields,
    ) {}
}
