<?php

declare(strict_types=1);

namespace App\Shared\Application\Transaction;

use Closure;

/**
 * Runs a unit of work atomically.
 *
 * Services depend on this rather than on the database connection, so "these writes commit together" is
 * expressed without granting the ability to run arbitrary SQL — and so a unit test can supply a
 * pass-through implementation with no database.
 */
interface TransactionRunnerInterface
{
    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function run(Closure $work): mixed;
}
