<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Shared\Application\Transaction\TransactionRunnerInterface;
use Closure;

/**
 * Runs the work immediately, without a database transaction. For unit tests of services that need a
 * transaction boundary but no real database.
 */
final class ImmediateTransactionRunner implements TransactionRunnerInterface
{
    public function run(Closure $work): mixed
    {
        return $work();
    }
}
