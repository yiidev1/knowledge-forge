<?php

declare(strict_types=1);

namespace App\Document\Domain;

/**
 * The minimal read model for a generated (source-derived) document: enough to decide whether a source
 * change needs re-indexing and to locate its stored text. Used by the Order58 sync; uploads use the
 * richer {@see Document} read model.
 */
final readonly class GeneratedDocument
{
    public function __construct(
        public int $id,
        public ?string $sourceSyncHash,
        public string $status,
        public string $storedPath,
        public string $storageToken,
        public bool $isSourceOverridden = false,
        public bool $isEnabled = true,
    ) {}

    public function isDeleted(): bool
    {
        return $this->status === DocumentStatus::Deleted->value;
    }

    /**
     * Explicitly disabled by an admin — as distinct from the Order58 "deleted" tombstone. A resync must not
     * silently revive it (re-enable / re-index), so the sync leaves it untouched until the admin re-enables.
     */
    public function isAdminDisabled(): bool
    {
        return !$this->isEnabled && !$this->isDeleted();
    }
}
