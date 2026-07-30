<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Domain;

/**
 * Whether a knowledge base is in active use or archived.
 *
 * Archiving is a soft, reversible retirement: the row and its documents are kept, but it drops out of
 * the default list and cannot be chatted with. Deleting outright is intentionally not offered here,
 * because it would also mean destroying uploaded documents and their remote vector store.
 */
enum KnowledgeBaseStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
