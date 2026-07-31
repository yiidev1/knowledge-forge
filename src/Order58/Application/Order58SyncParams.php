<?php

declare(strict_types=1);

namespace App\Order58\Application;

/**
 * Tunables for Order58 synchronization, built from `ORDER58_*` configuration.
 *
 * `pagesPerRun` bounds how many API pages a single worker invocation processes before yielding, so a
 * scan is resumable and never runs unbounded; `maxAttempts` caps retries of a failed sync run.
 */
final readonly class Order58SyncParams
{
    public function __construct(
        public int $pageSize,
        public int $maxAttempts,
        public int $pagesPerRun,
    ) {}
}
