<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * The four stable states of a transcription job.
 *
 * These are the contract: the worker's claim query, the queue limits and the ownership-free page guard
 * all key off them, and they are what the status endpoint reports. {@see ProcessingStage} carries the
 * finer-grained "what is it doing right now" detail and is deliberately kept separate, so adding a
 * pipeline step never changes the meaning of a status.
 */
enum JobStatus: string
{
    case QUEUED = 'QUEUED';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    /**
     * The statuses that occupy a queue slot. A job in one of these is claimable or already claimed;
     * anything else is terminal and no longer counts against either limit.
     *
     * @return non-empty-list<string>
     */
    public static function activeValues(): array
    {
        return [self::QUEUED->value, self::PROCESSING->value];
    }

    /**
     * @return non-empty-list<string>
     */
    public static function terminalValues(): array
    {
        return [self::COMPLETED->value, self::FAILED->value];
    }

    public function isActive(): bool
    {
        return $this === self::QUEUED || $this === self::PROCESSING;
    }

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => 'Queued',
            self::PROCESSING => 'Processing',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
        };
    }

    /** Unknown or malformed stored values read as QUEUED-safe rather than throwing in a template. */
    public static function fromStorage(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::FAILED;
    }
}
