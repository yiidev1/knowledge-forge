<?php

declare(strict_types=1);

namespace App\Order58\Application\Items;

/**
 * The five answers an item sync can give.
 *
 * `Empty` and `Failed` are deliberately separate cases rather than "zero items": one means the order
 * has no lines, the other means we could not find out. The comparison page treats the first as
 * authoritative and the second as not comparable, and collapsing them would make a trainee's correct
 * empty order indistinguishable from a provider outage.
 */
enum ItemSyncResult: string
{
    case Synced = 'SYNCED';
    case Empty = 'EMPTY';
    /** Stored items already matched the response. Still a success, still updates freshness. */
    case Unchanged = 'UNCHANGED';
    case Failed = 'FAILED';
    /** A live lease is held elsewhere; no API request was made. */
    case Busy = 'BUSY';
    /**
     * The feature is switched off or not configured. Nothing was claimed, attempted or recorded.
     *
     * Deliberately not `Failed`. A failure is a statement about the provider — it spends an attempt,
     * sets a retry clock and leaves the order marked as having something wrong with it. "Nobody has
     * turned this on yet" is a statement about *us*, and recording it against the order would make
     * every order look broken the moment somebody clicks a button before configuration exists.
     */
    case Unavailable = 'UNAVAILABLE';
}
