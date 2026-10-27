<?php

declare(strict_types=1);

namespace App\Order58\Domain\Items;

/**
 * What one batch's interest in one order came to.
 *
 * It describes what a BATCH is owed rather than what a request did, and the difference is `Skipped`:
 * an order already synchronised with an unmoved parent was never going to be fetched for this batch,
 * and recording it as `Unchanged` would claim a request that never happened.
 *
 * ## Why it outlived the endpoint it was written beside
 *
 * It used to be built from the per-order items request's own result, through a `fromSyncResult()`
 * translator that went with that endpoint in Stage 7a. The batches themselves did not go: the Orders
 * page still reports per-operation progress from them. So the enum stays and is set directly by
 * whatever settles a batch.
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
}
