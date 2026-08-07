<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * The admin-local "offered to agents" axis (`knowledge_bases.agent_enabled`) — independent of source-active and
 * of KB readiness, so it is never conflated with either.
 */
enum StoreAgentAvailabilityFilter: string
{
    case All = 'all';
    case Enabled = 'enabled';
    case Disabled = 'disabled';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::All;
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All agents',
            self::Enabled => 'Agent enabled',
            self::Disabled => 'Agent disabled',
        };
    }
}
