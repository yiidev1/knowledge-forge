<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * Operational readiness of one synced Order58 rule (source-mirror grain), derived from local DB state:
 * source activity, catalog availability, and — when present — the global projection's index-file snapshot.
 *
 * "Pending" is a user-facing grouping of Queued/Processing/Indexing. Ready is the only state that counts
 * toward Rule Chat usability.
 */
enum RuleReadinessStatus: string
{
    case Ready = 'ready';
    case Queued = 'queued';
    case Processing = 'processing';
    case Indexing = 'indexing';
    case Failed = 'failed';
    case Disabled = 'disabled';
    case Inactive = 'inactive';
    case NotMaterialized = 'not_materialized';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Indexing => 'Indexing',
            self::Failed => 'Failed',
            self::Disabled => 'Disabled',
            self::Inactive => 'Inactive',
            self::NotMaterialized => 'Not materialized',
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
            self::Disabled, self::Inactive, self::NotMaterialized => 'muted',
        };
    }

    /** Queued/Processing/Indexing roll up into the user-facing "Pending" bucket. */
    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Processing || $this === self::Indexing;
    }
}
