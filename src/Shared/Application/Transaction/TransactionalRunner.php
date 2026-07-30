<?php

declare(strict_types=1);

namespace App\Shared\Application\Transaction;

use Closure;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Runs a unit of work inside a database transaction.
 *
 * Application services depend on this rather than on `ConnectionInterface` directly, so "these writes
 * must commit together" is expressed without every service gaining the ability to run arbitrary SQL.
 */
final readonly class TransactionalRunner implements TransactionRunnerInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function run(Closure $work): mixed
    {
        /** @var T */
        return $this->connection->transaction($work);
    }
}
