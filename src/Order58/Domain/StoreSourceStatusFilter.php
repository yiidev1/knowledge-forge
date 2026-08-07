<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * The Order58 source-active axis of the store directory — deliberately SEPARATE from the knowledge-pipeline
 * {@see StoreDirectoryFilter} (Ready/Processing/…). A store can be source-inactive yet have a Ready KB, so the
 * two are never merged into one ambiguous status.
 */
enum StoreSourceStatusFilter: string
{
    case All = 'all';
    case Active = 'active';
    case Inactive = 'inactive';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::All;
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All sources',
            self::Active => 'Source active',
            self::Inactive => 'Source inactive',
        };
    }
}
