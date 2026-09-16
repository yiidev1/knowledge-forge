<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * One physical recording inside a conversation, as the store history and conversion page need it.
 *
 * A projection rather than the whole {@see TranscriptionJob}: these screens list several conversations
 * at a time, and pulling every transcript and both segment columns to print a filename and a status
 * would be a lot of text nobody reads.
 */
final readonly class AudioConversationChild
{
    public function __construct(
        public string $publicId,
        public SourceRole $sourceRole,
        public JobStatus $status,
        public ?ProcessingStage $stage,
        public string $originalFilename,
        public ?float $durationSeconds,
        public ?string $errorMessage,
        /**
         * Which engine transcribed this recording, already resolved.
         *
         * Not nullable here, unlike on the job row: the NULL-means-Whisper rule is applied once, where
         * the row is read, so a template never has to know the legacy case existed.
         */
        public TranscriptionProvider $transcriptionProvider = TranscriptionProvider::Whisper,
    ) {}
}
