<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * The chat-availability axis of the store directory — whether a store can actually open chat right now, by the
 * ONE canonical eligibility rule ({@see \App\KnowledgeBase\Infrastructure\KnowledgeBaseChatEligibilitySql}: source
 * active, KB ready, a usable indexed genuine-content document, and a store profile). It is deliberately separate
 * from the knowledge-pipeline {@see StoreDirectoryFilter} (Ready/Processing/…): a store can be "Ready" yet still be
 * chat-unavailable (source inactive, vector store not provisioned, no store profile, …).
 */
enum StoreChatAvailabilityFilter: string
{
    case All = 'all';
    case Available = 'available';
    case Unavailable = 'unavailable';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::All;
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All chat',
            self::Available => 'Chat available',
            self::Unavailable => 'Chat unavailable',
        };
    }
}
