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
        /**
         * The store this recording was uploaded for, or null for a conversion that predates
         * store-wise audio. Legacy rows were back-filled with a conversation but no store, because
         * there was none to infer — see §9.3.
         */
        public ?int $storeSourceId = null,
        /**
         * Read from `knowledge_bases.name`, the same column the store picker sorts and displays, so
         * this table and the card that leads to a store never disagree about its name.
         */
        public ?string $storeName = null,
    ) {}
}
