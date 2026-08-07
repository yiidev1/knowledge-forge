<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * The freshness of one Order58 sync type, derived by {@see \App\Order58\Application\Order58SyncFreshnessService}
 * from `integration_sync_runs`. Distinct from a single run's status: it combines the last *successful* run, the
 * last *attempted* run and any in-flight run into one operator-facing signal.
 */
enum SyncFreshnessState: string
{
    case NeverSynced = 'never';
    case Syncing = 'syncing';
    case Fresh = 'fresh';
    case Warning = 'warning';
    case Stale = 'stale';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::NeverSynced => 'Never synced',
            self::Syncing => 'Syncing',
            self::Fresh => 'Fresh',
            self::Warning => 'Warning',
            self::Stale => 'Stale',
            self::Failed => 'Failed',
        };
    }

    /** The admin badge modifier (existing palette). */
    public function badge(): string
    {
        return match ($this) {
            self::Fresh => 'success',
            self::Syncing => 'info',
            self::Warning, self::Stale => 'warning',
            self::Failed => 'error',
            self::NeverSynced => 'muted',
        };
    }
}
