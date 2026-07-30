<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Worker;

use App\Worker\Application\WorkerLockInterface;

/**
 * A lock the test controls: whether it can be acquired, and whether it was released.
 */
final class FakeWorkerLock implements WorkerLockInterface
{
    public bool $released = false;

    private bool $acquired = false;

    public function __construct(
        private readonly bool $acquirable = true,
    ) {}

    public function acquire(): bool
    {
        if (!$this->acquirable) {
            return false;
        }

        $this->acquired = true;

        return true;
    }

    public function release(): void
    {
        if ($this->acquired) {
            $this->released = true;
        }
    }
}
