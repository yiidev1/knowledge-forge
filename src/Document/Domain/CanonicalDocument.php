<?php

declare(strict_types=1);

namespace App\Document\Domain;

/**
 * Read model for View / Download / Edit: the canonical local source plus provenance.
 *
 * Never carries a filesystem path from the request — callers load this by knowledge-base id and
 * document id, then resolve bytes only through storage.
 */
final readonly class CanonicalDocument
{
    public function __construct(
        public int $id,
        public int $knowledgeBaseId,
        public DocumentSourceType $sourceType,
        public DocumentKind $kind,
        public DocumentStatus $status,
        public string $title,
        public string $originalFilename,
        public string $storedPath,
        public string $storageToken,
        public string $mimeType,
        public string $extension,
        public int $sizeBytes,
        public string $checksumSha256,
        public ?string $sourceText,
        public ?string $sourceRef,
        public bool $isSourceOverridden,
    ) {}

    public function isManualText(): bool
    {
        return $this->sourceType === DocumentSourceType::ManualText;
    }

    public function isUploadedText(): bool
    {
        return $this->sourceType === DocumentSourceType::UploadedText;
    }

    public function isOrder58(): bool
    {
        return $this->sourceType->isOrder58Generated();
    }

    public function isBinaryUpload(): bool
    {
        return $this->kind === DocumentKind::Pdf || $this->kind === DocumentKind::Image;
    }

    public function displayTitle(): string
    {
        return $this->title !== '' ? $this->title : $this->originalFilename;
    }
}
