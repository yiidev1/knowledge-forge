<?php

declare(strict_types=1);

namespace App\Order58\Domain\CallRecordings;

/**
 * One stored order's `calls`, read back out of `payload_json` for the backfill.
 *
 * `calls` is the decoded JSON value exactly as stored — an object, a list, null, or absent — and is
 * interpreted by the same parser the synchronisation uses.
 */
final readonly class StoredOrderCalls
{
    public function __construct(
        public int $rowId,
        public int $storeSourceId,
        public int $sourceOrderId,
        public mixed $calls,
    ) {}
}
