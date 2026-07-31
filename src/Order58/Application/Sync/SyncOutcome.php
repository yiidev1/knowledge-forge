<?php

declare(strict_types=1);

namespace App\Order58\Application\Sync;

use App\Order58\Domain\SyncProgress;
use App\Order58\Domain\SyncRunStatus;

/**
 * The result a sync handler returns to the drainer. `terminalStatus === null` with `transientFailure ===
 * false` means "partial — more pages remain, stay running and resume next invocation"; with
 * `transientFailure === true` it means "a retryable error, requeue with backoff". A non-null
 * `terminalStatus` finalizes the run.
 */
final readonly class SyncOutcome
{
    private function __construct(
        public SyncProgress $progress,
        public ?SyncRunStatus $terminalStatus,
        public bool $transientFailure,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {}

    public static function partial(SyncProgress $progress): self
    {
        return new self($progress, null, false, null, null);
    }

    public static function completed(SyncProgress $progress): self
    {
        return new self($progress, SyncRunStatus::Completed, false, null, null);
    }

    public static function completedWithWarnings(SyncProgress $progress, string $message): self
    {
        return new self($progress, SyncRunStatus::CompletedWithWarnings, false, null, $message);
    }

    public static function failed(SyncProgress $progress, ?string $errorCode, ?string $errorMessage): self
    {
        return new self($progress, SyncRunStatus::Failed, false, $errorCode, $errorMessage);
    }

    public static function transient(SyncProgress $progress, ?string $errorCode, ?string $errorMessage): self
    {
        return new self($progress, null, true, $errorCode, $errorMessage);
    }
}
