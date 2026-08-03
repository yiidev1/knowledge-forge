<?php

declare(strict_types=1);

namespace App\Document\Domain;

/**
 * The editable form of a text-like document — title, body text, and enough to detect a real change.
 */
final readonly class EditableTextDocument
{
    public function __construct(
        public int $id,
        public DocumentSourceType $sourceType,
        public string $title,
        public string $sourceText,
        public string $checksum,
        public string $storedPath,
        public bool $isSourceOverridden = false,
    ) {}

    public function isManual(): bool
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
}
