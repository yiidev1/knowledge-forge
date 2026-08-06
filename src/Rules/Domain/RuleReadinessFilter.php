<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * The top-level operational filter on the rule-readiness page. Deliberately small — classification, duplicate,
 * ambiguous and review-state filters live only on the advanced /rules/list page.
 */
enum RuleReadinessFilter: string
{
    case All = 'all';
    case Ready = 'ready';
    case Pending = 'pending';
    case Failed = 'failed';
    case Disabled = 'disabled';

    public static function fromRequest(?string $value): self
    {
        return $value === null ? self::All : (self::tryFrom($value) ?? self::All);
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All',
            self::Ready => 'Ready',
            self::Pending => 'Pending',
            self::Failed => 'Failed',
            self::Disabled => 'Disabled',
        };
    }

    /**
     * The operational statuses this filter selects (empty = no status restriction).
     *
     * @return list<string>
     */
    public function statuses(): array
    {
        return match ($this) {
            self::All => [],
            self::Ready => [RuleReadinessStatus::Ready->value],
            self::Pending => [
                RuleReadinessStatus::Queued->value,
                RuleReadinessStatus::Processing->value,
                RuleReadinessStatus::Indexing->value,
            ],
            self::Failed => [RuleReadinessStatus::Failed->value],
            self::Disabled => [RuleReadinessStatus::Disabled->value],
        };
    }
}
