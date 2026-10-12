<?php

declare(strict_types=1);

namespace App\Order58\Application\Orders;

use RuntimeException;

use function sprintf;

/**
 * Another sync already holds this store and date range.
 *
 * Not a failure of the request — the request was well formed and was declined because somebody else is
 * already doing exactly this work. Each caller decides what that means: the web action turns it into
 * the same message it has always shown, and the recording worker logs it and carries on, because an
 * order that was not fetched this tick will be fetched next time.
 *
 * It carries the store and range rather than a formatted sentence, so the wording stays with whoever
 * is doing the telling.
 */
final class OrderSyncInProgress extends RuntimeException
{
    public function __construct(
        public readonly int $accountId,
        public readonly OrderDateRange $range,
    ) {
        parent::__construct(sprintf(
            'An Order58 order sync is already running for store %d, %s.',
            $accountId,
            $range->label(),
        ));
    }
}
