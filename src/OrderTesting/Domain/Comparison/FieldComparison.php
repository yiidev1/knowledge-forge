<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

/** One labelled field, both sides of it, and what comparing them found. */
final readonly class FieldComparison
{
    public function __construct(
        public string $label,
        /** As recorded, for display. Never canonicalised — the operator sees what the document says. */
        public ?string $original,
        public ?string $demo,
        public ComparisonStatus $status,
    ) {}
}
