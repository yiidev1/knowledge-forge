<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * A candidate rule→store link produced by the matcher: which store, by what method, how confident, and whether
 * it is strong enough to confirm (exact) or only to suggest (fuzzy / ambiguous).
 */
final readonly class StoreLinkCandidate
{
    public function __construct(
        public int $storeSourceId,
        public StoreMatchMethod $method,
        public StoreMatchStatus $status,
        public float $confidence,
        public ?string $matchedText,
    ) {}
}
