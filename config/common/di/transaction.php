<?php

declare(strict_types=1);

use App\Shared\Application\Transaction\TransactionalRunner;
use App\Shared\Application\Transaction\TransactionRunnerInterface;

return [
    TransactionRunnerInterface::class => TransactionalRunner::class,
];
