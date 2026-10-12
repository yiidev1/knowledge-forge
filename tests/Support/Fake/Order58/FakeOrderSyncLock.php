<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Order58;

use App\Order58\Application\Orders\OrderSyncLockInterface;

use function array_filter;
use function array_values;

/**
 * The order-sync lock, in memory.
 *
 * The real one is a MySQL advisory lock, so a unit test that wanted to exercise {@see
 * \App\Order58\Application\Orders\OrderSyncService} would otherwise need a database for a collaborator
 * that has nothing to do with what it is testing.
 *
 * `$granted = false` makes every acquisition fail, which is how a test stands in for "somebody else is
 * already syncing this store and range". `$held` records what is still outstanding, so a test can
 * assert the service released on the path it took — including the failure paths, where a leaked lock
 * would block that store and range until the connection closed.
 */
final class FakeOrderSyncLock implements OrderSyncLockInterface
{
    /** @var list<string> names acquired and not yet released */
    public array $held = [];

    /** @var list<string> every name ever acquired, in order */
    public array $acquired = [];

    public function __construct(private readonly bool $granted = true) {}

    public function acquire(string $name): bool
    {
        if (!$this->granted) {
            return false;
        }

        $this->acquired[] = $name;
        $this->held[] = $name;

        return true;
    }

    public function release(string $name): void
    {
        $this->held = array_values(array_filter(
            $this->held,
            static fn(string $held): bool => $held !== $name,
        ));
    }
}
