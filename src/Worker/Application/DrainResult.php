<?php

declare(strict_types=1);

namespace App\Worker\Application;

/**
 * The outcome of draining one kind of background work: how many items reached a terminal state and how
 * many of those ended in failure. Used by the worker to compute its exit code.
 */
final readonly class DrainResult
{
    public function __construct(
        public int $processed,
        public int $failed,
    ) {}

    public static function nothing(): self
    {
        return new self(0, 0);
    }

    public function add(self $other): self
    {
        return new self($this->processed + $other->processed, $this->failed + $other->failed);
    }
}
