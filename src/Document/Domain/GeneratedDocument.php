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
    ) {}

    public function isDeleted(): bool
    {
        return $this->status === DocumentStatus::Deleted->value;
    }
}
