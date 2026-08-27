<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use DateTimeImmutable;

/**
 * A row of the global conversions table.
 *
 * A separate read model rather than the full {@see TranscriptionJob} because the list shows many rows
 * and three transcript columns: hydrating whole transcripts to render an 80-character preview would pull
 * megabytes into a page that displays a few kilobytes. The previews are truncated in SQL, so the full
 * text never leaves the database for this view at all.
 */
final readonly class TranscriptionJobListItem
{
    public function __construct(
        public string $publicId,
        public ?string $uploadedByUsername,
        public JobStatus $status,
        public ?ProcessingStage $stage,
        public string $originalFilename,
        public ?float $durationSeconds,
        public ?string $detectedLanguage,
        public ?string $transcriptPreview,
        public ?string $agentTextPreview,
        public ?string $customerTextPreview,
        public ?SpeakerSeparationStatus $speakerSeparationStatus,
        public ?string $errorMessage,
        public DateTimeImmutable $createdAt,
        public bool $downloadable,
    ) {}
}
