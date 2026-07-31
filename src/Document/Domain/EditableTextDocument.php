<?php

declare(strict_types=1);

namespace App\Document\Domain;

/**
 * The editable form of a text document — the original submitted text plus enough to detect a real change.
 * Only manual-text documents are editable; the flag lets the web layer refuse to edit an uploaded file.
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
    ) {}

    public function isManual(): bool
    {
        return $this->sourceType === DocumentSourceType::ManualText;
    }
}
