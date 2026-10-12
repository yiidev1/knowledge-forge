<?php

declare(strict_types=1);

namespace App\Order58\Domain\Items;

use App\Order58\Application\Items\ItemSyncResult;

/**
 * What one batch's interest in one order came to.
 *
 * Separate from {@see ItemSyncResult} on purpose. That describes what a provider request did; this
 * describes what a BATCH is owed. The difference is `Skipped`: an order already synchronised with an
 * unmoved parent was never going to be fetched for this batch, and recording it as `Unchanged` would
 * claim a request that never happened.
 */
enum EnrolmentOutcome: string
{
    case Synced = 'SYNCED';
    case Empty = 'EMPTY';
    case Unchanged = 'UNCHANGED';

    /** Nothing was owed: already current when this batch enrolled it. No request was made. */
    case Skipped = 'SKIPPED';

    case Failed = 'FAILED';

    /** Whether this counts against the batch's verdict. */
    public function isFailure(): bool
    {
        return $this === self::Failed;
    }

    /**
     * The enrolment outcome for a finished provider request.
     *
     * `Busy` and `Unavailable` are deliberately absent: neither is terminal. Busy means somebody else
     * holds the order and will settle it; unavailable means the feature is switched off and nothing was
     * attempted. Settling on either would close a batch over work that has not happened.
     */
    public static function fromSyncResult(ItemSyncResult $result): ?self
    {
        return match ($result) {
            ItemSyncResult::Synced => self::Synced,
            ItemSyncResult::Empty => self::Empty,
            ItemSyncResult::Unchanged => self::Unchanged,
            ItemSyncResult::Failed => self::Failed,
            ItemSyncResult::Busy, ItemSyncResult::Unavailable => null,
        };
    }
}
