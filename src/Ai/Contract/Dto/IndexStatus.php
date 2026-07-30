<?php

declare(strict_types=1);

namespace App\Ai\Contract\Dto;

/**
 * Provider-neutral status of a file within a vector store, normalised from the provider's own vocabulary.
 */
enum IndexStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            self::Pending, self::InProgress => false,
        };
    }

    public function isSuccess(): bool
    {
        return $this === self::Completed;
    }
}
