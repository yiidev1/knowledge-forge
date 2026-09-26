<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use function ceil;
use function max;

/**
 * One page of import history: the calls on this page, and how many there are in total.
 *
 * `total` counts **calls**, not rows. A call is up to three channel rows, so counting rows would report
 * roughly three times too many and give the pager pages that do not exist.
 *
 * Shaped like {@see StoreDirectoryResult} so the shared pager partial reads it without a second
 * convention to learn.
 */
final readonly class CallImportHistoryPage
{
    /**
     * @param list<CallImportHistoryRow> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
