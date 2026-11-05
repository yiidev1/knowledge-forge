<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use DateTimeImmutable;

/**
 * Which stores the Manage Orders and Practice Orders pickers list: those with a Case Order placed in the
 * last 30 days.
 *
 * ## A rolling window, on Order58's clock
 *
 * The window is the 30 × 24 hours before now, compared with `order58_orders.source_created_at` — the
 * moment Order58 says the customer placed the order. Not the synchronisation time: one sync imports a
 * year of orders at once, and a store whose newest order is months old must not reappear because that
 * order was fetched this morning. Both sides are Unix seconds, so no time zone enters the comparison and
 * the boundary is inclusive: an order placed exactly 30 days ago still counts.
 *
 * ## What it narrows, and what it does not
 *
 * Only which store **cards** the two pickers show. A store outside the window keeps its orders, its
 * recordings and its pages; its address still opens it. The cards' own counts are unchanged too — they
 * still count every order, not only the recent ones.
 */
final readonly class RecentCaseOrderWindow
{
    public const DAYS = 30;

    /** The earliest qualifying creation time, Unix seconds, for a picker rendered at `$now`. */
    public static function since(DateTimeImmutable $now): int
    {
        return $now->getTimestamp() - self::DAYS * 86_400;
    }
}
