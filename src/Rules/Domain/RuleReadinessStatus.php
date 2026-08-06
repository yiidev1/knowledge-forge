<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * The operational stage of a materialized rule document, derived from the durable index-file snapshot (never
 * from `documents.status` alone). "Pending" is a user-facing grouping of Queued/Processing/Indexing.
 */
enum RuleReadinessStatus: string
{
    case Ready = 'ready';
    case Queued = 'queued';
    case Processing = 'processing';
    case Indexing = 'indexing';
    case Failed = 'failed';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Indexing => 'Indexing',
            self::Failed => 'Failed',
            self::Disabled => 'Disabled',
        };
    }

    /** The admin badge modifier for this stage (uses the existing badge palette). */
    public function badge(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Queued, self::Processing => 'info',
            self::Indexing => 'warning',
            self::Failed => 'error',
            self::Disabled => 'muted',
        };
    }

    /** Queued/Processing/Indexing roll up into the user-facing "Pending" bucket. */
    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Processing || $this === self::Indexing;
    }
}
