<?php

declare(strict_types=1);

namespace App\Order58\Domain\Items;

use function max;
use function min;
use function round;

/**
 * How far one store's original-order-item synchronisation has got.
 *
 * ## The scope is part of the answer
 *
 * These counts cover **every mirrored order in the current store filter** — one store when the listing
 * is filtered to one, every stored order when it is not. That is the same set the table underneath is
 * drawn from, which is what makes the bar read as a statement about this page rather than about some
 * other selection.
 *
 * It deliberately does **not** follow the date, order-id or status filters. Those narrow which rows are
 * displayed; the bar answers "how much of this store's order history has its items", which is a
 * question about the store, not about the current page of results. The page says so in words beside the
 * numbers, so a figure can never be read as meaning something it does not.
 *
 * ## Why "ready" is not the same as "synced"
 *
 * An order whose latest refresh failed, or is running right now, over a snapshot that succeeded earlier
 * is still comparable — the kept snapshot is real item data. So it counts toward `ready` and is also
 * counted in `stale`, which is what lets the page say "comparable, but some of it is from an earlier
 * fetch" rather than having to choose between the two.
 */
final readonly class ItemSyncSummary
{
    public function __construct(
        /** Mirrored orders in scope: one store, or every store when unfiltered. */
        public int $total,
        /** Comparable right now: synced, authoritatively empty, or carrying a kept snapshot. */
        public int $ready,
        /** Never requested — the state every historical order starts in. */
        public int $notSynced,
        /** Queued, or fetching for the first time. */
        public int $inProgress,
        /** Failed with nothing ever stored: the only state where the items are unknown. */
        public int $failed,
        /** A subset of `$ready`: comparable, but from an earlier fetch than the latest attempt. */
        public int $stale,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, 0);
    }

    /** No mirrored orders in scope at all, so there is no progress to report. */
    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    /** Whole percent, for the bar. Never divides by zero and never reads 100% while work remains. */
    public function readyPercent(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        $percent = (int) round($this->ready / $this->total * 100);

        // A single outstanding order must not round up to a finished-looking 100%.
        return $this->ready < $this->total ? min($percent, 99) : 100;
    }

    /** Whether anything at all needs an operator's attention. */
    public function needsAttention(): bool
    {
        return $this->failed > 0 || $this->stale > 0;
    }

    /** Orders still to fetch, for the one-line summary. */
    public function outstanding(): int
    {
        return max(0, $this->total - $this->ready);
    }
}
