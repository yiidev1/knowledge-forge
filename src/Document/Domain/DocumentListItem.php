<?php

declare(strict_types=1);

namespace App\Document\Domain;

use DateTimeImmutable;

use function number_format;

/**
 * A row of the knowledge-base document list: enough to render every source type with its status, enabled
 * state and per-row actions, in one query, without loading the full {@see Document} read model.
 */
final readonly class DocumentListItem
{
    public function __construct(
        public int $id,
        public string $title,
        public DocumentSourceType $sourceType,
        public DocumentKind $kind,
        public DocumentStatus $status,
        public bool $isEnabled,
        public int $sizeBytes,
        public ?string $errorMessage,
        public DateTimeImmutable $createdAt,
    ) {}

    public function isManualText(): bool
    {
        return $this->sourceType === DocumentSourceType::ManualText;
    }

    /**
     * The simplified status shown to a normal admin (Ready / Processing / Failed / Disabled).
     */
    public function displayStatus(): DocumentDisplayStatus
    {
        return DocumentDisplayStatus::for($this->status, $this->isEnabled);
    }

    public function humanSize(): string
    {
        if ($this->sizeBytes < 1024) {
            return $this->sizeBytes . ' B';
        }
        if ($this->sizeBytes < 1024 * 1024) {
            return number_format($this->sizeBytes / 1024, 1) . ' KB';
        }

        return number_format($this->sizeBytes / (1024 * 1024), 1) . ' MB';
    }
}
