<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Application\Orders\OrderSyncLockInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The order-sync lock, as a MySQL advisory lock.
 *
 * `GET_LOCK` rather than a row: it is released when its connection ends — including when PHP dies
 * mid-request — so a crashed sync cannot leave a flag that blocks every future one. A table-based flag
 * would need a sweeper, and the sweeper would have to guess how long a sync is allowed to take.
 *
 * Zero timeout, which is the whole contract: a second caller is refused immediately rather than
 * queueing behind the first.
 */
final readonly class DbOrderSyncLock implements OrderSyncLockInterface
{
    public function __construct(private ConnectionInterface $connection) {}

    public function acquire(string $name): bool
    {
        return (int) $this->connection
            ->createCommand('SELECT GET_LOCK(:name, 0)', [':name' => $name])
            ->queryScalar() === 1;
    }

    public function release(string $name): void
    {
        $this->connection->createCommand('SELECT RELEASE_LOCK(:name)', [':name' => $name])->queryScalar();
    }
}
