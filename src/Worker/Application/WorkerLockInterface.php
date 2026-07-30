<?php

declare(strict_types=1);

namespace App\Worker\Application;

/**
 * A single-holder lock ensuring only one worker runs at a time.
 *
 * Non-blocking by contract: {@see acquire()} returns false immediately if another worker holds the lock,
 * so a cron firing every minute never stacks overlapping runs. The lock is released when the process
 * ends even if it is not released explicitly.
 */
interface WorkerLockInterface
{
    public function acquire(): bool;

    public function release(): void;
}
