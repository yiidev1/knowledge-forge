<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Dto;

use function is_numeric;

/**
 * The per-status file tally OpenAI reports on a vector store.
 *
 * `total` is what the provider reported, not a recomputed sum of the parts. If the two ever disagree
 * that is a fact worth surfacing on the dashboard rather than one to quietly correct, so nothing here
 * derives one from the others.
 */
final readonly class VectorStoreFileCounts
{
    public function __construct(
        public int $total = 0,
        public int $completed = 0,
        public int $inProgress = 0,
        public int $failed = 0,
        public int $cancelled = 0,
    ) {}

    public static function zero(): self
    {
        return new self();
    }

    public function plus(self $other): self
    {
        return new self(
            total: $this->total + $other->total,
            completed: $this->completed + $other->completed,
            inProgress: $this->inProgress + $other->inProgress,
            failed: $this->failed + $other->failed,
            cancelled: $this->cancelled + $other->cancelled,
        );
    }

    /**
     * @return array{total: int, completed: int, in_progress: int, failed: int, cancelled: int}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'completed' => $this->completed,
            'in_progress' => $this->inProgress,
            'failed' => $this->failed,
            'cancelled' => $this->cancelled,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $int = static fn(string $key): int => isset($data[$key]) && is_numeric($data[$key]) ? (int) $data[$key] : 0;

        return new self(
            total: $int('total'),
            completed: $int('completed'),
            inProgress: $int('in_progress'),
            failed: $int('failed'),
            cancelled: $int('cancelled'),
        );
    }
}
