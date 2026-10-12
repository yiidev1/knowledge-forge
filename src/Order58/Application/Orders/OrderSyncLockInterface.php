<?php

declare(strict_types=1);

namespace App\Order58\Application\Orders;

/**
 * A single-holder lock for one store and date range, so two order syncs cannot run over the same work.
 *
 * ## Why this exists as a port
 *
 * The lock used to be two lines inside {@see \App\Order58\Web\Orders\SyncAction}, which left the other
 * caller unprotected: `kf:order58:import-recordings` calls {@see OrderSyncService} directly after a
 * settled recording batch and acquired nothing, so a scheduled recording run and an administrator
 * pressing "Sync orders" could fetch, map and write the same store and day at once. The command's own
 * docblock claimed the service held a lock; it did not.
 *
 * Moving it behind this interface puts it where the work is — every caller is covered by construction
 * — and keeps {@see OrderSyncService} testable without a database, which is how the existing unit
 * tests exercise it.
 *
 * Non-blocking by contract: {@see acquire()} returns false immediately rather than queueing. A second
 * administrator is told at once, and a recording tick declines and moves on instead of holding a
 * connection open waiting for work somebody else is already doing.
 *
 * The same shape as {@see \App\Worker\Application\WorkerLockInterface}, which this project already uses
 * for the background worker's single-holder lock.
 */
interface OrderSyncLockInterface
{
    public function acquire(string $name): bool;

    public function release(string $name): void;
}
