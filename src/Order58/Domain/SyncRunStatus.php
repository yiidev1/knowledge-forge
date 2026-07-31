<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * The lifecycle of a synchronization run: pending → running → (completed | completed_with_warnings |
 * failed). Only pending/running are "active" (they participate in the coalescing unique index and block a
 * duplicate click of the same operation).
 */
enum SyncRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case CompletedWithWarnings = 'completed_with_warnings';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::CompletedWithWarnings => 'Completed with warnings',
            self::Failed => 'Failed',
        };
    }

    /**
     * A CSS modifier for the status badge in the admin UI.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::Running => 'processing',
            self::Completed => 'ready',
            self::CompletedWithWarnings => 'warning',
            self::Failed => 'failed',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }

    public function isTerminal(): bool
    {
        return !$this->isActive();
    }
}
