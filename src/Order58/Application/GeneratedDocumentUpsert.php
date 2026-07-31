<?php

declare(strict_types=1);

namespace App\Order58\Application;

/**
 * The outcome of upserting a generated document: whether it was newly created, re-indexed after a change,
 * or left untouched because the source `_sync_hash` had not changed.
 */
enum GeneratedDocumentUpsert
{
    case Created;
    case Updated;
    case Unchanged;

    /**
     * True when work was queued for indexing (created or re-indexed).
     */
    public function queuedIndexing(): bool
    {
        return $this === self::Created || $this === self::Updated;
    }
}
