<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * The parameters of an admin store-directory lookup: free-text search, a status {@see StoreDirectoryFilter},
 * an alphabet bucket ("all", "A".."Z" or "#"), and 1-based pagination. Kept as a value object so the reader
 * and its tests share one shape.
 */
final readonly class StoreDirectoryQuery
{
    public function __construct(
        public string $search = '',
        public StoreDirectoryFilter $filter = StoreDirectoryFilter::All,
        public string $letter = 'all',
        public int $page = 1,
        public int $perPage = 24,
        /**
         * Restrict to stores that can actually be chatted with (vector store ready and at least one enabled,
         * ready document). Used by the admin "Store chat" picker; the full directory leaves it false.
         */
        public bool $chatReadyOnly = false,
    ) {}

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
