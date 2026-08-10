<?php

declare(strict_types=1);

namespace App\Web\Shared\Pagination;

use function array_filter;
use function array_unique;
use function array_values;
use function max;
use function min;
use function range;
use function sort;

/**
 * Builds the numbered-page window for list pagination (Previous / 1 … 64 65 [66] 67 68 … 136 / Next).
 *
 * Returns a list of page numbers with {@see null} markers for non-interactive ellipsis gaps. Callers never
 * need to load the full dataset — only {@see $pageCount} and {@see $currentPage} are required.
 */
final class PageWindow
{
    /** When total pages are at or below this, every page number is shown (no ellipsis). */
    public const SMALL_THRESHOLD = 7;

    /** How many leading/trailing pages to show when the current page is near an end. */
    public const EDGE_SPAN = 6;

    /** Pages on each side of the current page in the middle of a large set. */
    public const RADIUS = 2;

    /**
     * @return list<int|null> Page numbers, or null for an ellipsis gap. Empty when there is nothing to paginate.
     */
    public static function items(int $currentPage, int $pageCount): array
    {
        if ($pageCount <= 1) {
            return [];
        }

        $current = self::clamp($currentPage, $pageCount);

        if ($pageCount <= self::SMALL_THRESHOLD) {
            return range(1, $pageCount);
        }

        $pages = [1, $pageCount];

        if ($current <= 4) {
            for ($i = 2; $i <= self::EDGE_SPAN; $i++) {
                $pages[] = $i;
            }
        } elseif ($current >= $pageCount - 3) {
            for ($i = $pageCount - self::EDGE_SPAN + 1; $i < $pageCount; $i++) {
                if ($i > 1) {
                    $pages[] = $i;
                }
            }
        } else {
            for ($i = $current - self::RADIUS; $i <= $current + self::RADIUS; $i++) {
                $pages[] = $i;
            }
        }

        $pages = array_values(array_unique(array_filter(
            $pages,
            static fn(int $p): bool => $p >= 1 && $p <= $pageCount,
        )));
        sort($pages);

        $result = [];
        $previous = null;
        foreach ($pages as $page) {
            if ($previous !== null && $page - $previous > 1) {
                $result[] = null;
            }
            $result[] = $page;
            $previous = $page;
        }

        return $result;
    }

    /** Safe page number in 1..pageCount (pageCount &lt; 1 ⇒ 1). */
    public static function clamp(int $page, int $pageCount): int
    {
        if ($pageCount < 1) {
            return 1;
        }

        return max(1, min($page, $pageCount));
    }
}
