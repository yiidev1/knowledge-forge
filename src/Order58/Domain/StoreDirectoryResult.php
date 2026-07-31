<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use function array_key_exists;
use function ceil;
use function max;

/**
 * A page of admin store-directory results: the rows for the current page, the total number of stores that
 * match the search + filter (for pagination), and the per-letter counts for the alphabet nav. The letter
 * counts reflect the search + filter but not the selected letter, so switching letters is stable.
 */
final readonly class StoreDirectoryResult
{
    /**
     * @param list<StoreDirectoryItem> $items
     * @param array<string, int>       $letterCounts keyed by "all", "A".."Z", "#"
     */
    public function __construct(
        public array $items,
        public int $total,
        public array $letterCounts,
        public int $page,
        public int $perPage,
    ) {}

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function countFor(string $letter): int
    {
        return array_key_exists($letter, $this->letterCounts) ? $this->letterCounts[$letter] : 0;
    }
}
