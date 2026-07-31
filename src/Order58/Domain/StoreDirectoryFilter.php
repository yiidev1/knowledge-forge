<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * The admin store-directory filters, expressed in the same friendly knowledge vocabulary as the store card —
 * not the underlying technical axes. Each value slices the directory by the computed
 * {@see StoreKnowledgeStatus}, so a normal admin never sees source-active / agent-enabled / vector-store
 * states as filters.
 */
enum StoreDirectoryFilter: string
{
    case All = 'all';
    case Ready = 'ready';
    case Processing = 'processing';
    case Failed = 'failed';
    case NoKnowledge = 'no_knowledge';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::All;
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All',
            self::Ready => 'Ready',
            self::Processing => 'Processing',
            self::Failed => 'Failed',
            self::NoKnowledge => 'No knowledge',
        };
    }
}
