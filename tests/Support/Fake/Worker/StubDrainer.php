<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Worker;

use App\Worker\Application\DrainerInterface;
use App\Worker\Application\DrainResult;
use RuntimeException;

/**
 * A drainer with a scripted result, for testing the runner's aggregation, ordering and error handling
 * without any real work. It records that recover() ran, and can be told to throw from drain() to
 * simulate an infrastructure fault escaping a drainer.
 */
final class StubDrainer implements DrainerInterface
{
    public bool $recovered = false;

    public bool $drained = false;

    public function __construct(
        private readonly string $name,
        private readonly DrainResult $result,
        private readonly bool $throwOnDrain = false,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function recover(): void
    {
        $this->recovered = true;
    }

    public function drain(int $limit): DrainResult
    {
        $this->drained = true;

        if ($this->throwOnDrain) {
            throw new RuntimeException('boom');
        }

        return $this->result;
    }
}
