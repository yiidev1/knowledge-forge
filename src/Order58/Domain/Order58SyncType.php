<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * The kinds of synchronization run. The three primary operations are independent; the two scoped ones
 * carry a store id in `scope_ref`. `health` is a bounded connection check.
 */
enum Order58SyncType: string
{
    case Stores = 'stores';
    case Knowledge = 'knowledge';
    case Agents = 'agents';
    case KnowledgeStore = 'knowledge_store';
    case RebuildStore = 'rebuild_store';
    case Health = 'health';

    public function label(): string
    {
        return match ($this) {
            self::Stores => 'Sync Stores',
            self::Knowledge => 'Sync Knowledge',
            self::Agents => 'Sync Agents',
            self::KnowledgeStore => "Sync This Store's Knowledge",
            self::RebuildStore => 'Rebuild Generated Documents',
            self::Health => 'Check Connection',
        };
    }

    /**
     * Scoped operations act on a single store (`scope_ref` is required).
     */
    public function isScoped(): bool
    {
        return $this === self::KnowledgeStore || $this === self::RebuildStore;
    }

    /**
     * The three primary buttons on the Data Management page.
     */
    public function isPrimary(): bool
    {
        return $this === self::Stores || $this === self::Knowledge || $this === self::Agents;
    }
}
