<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use DateTimeImmutable;

/**
 * One transcription job, as the application sees it.
 *
 * Note what is absent: there is no `isOwnedBy()`. This is a shared administrator demo, so every
 * authorized administrator may view every job, and `uploadedByAdminId` exists purely as audit metadata
 * and to drive the per-administrator enqueue limit. Authorization is "authenticated administrator plus
 * the job exists" and lives in the route middleware and the page guard, not here — an ownership
 * predicate on the entity would be an invitation to reintroduce a check the product does not want.
 *
 * `id` never leaves the server. Every URL uses `publicId`, 32 random hex characters, so job addresses
 * are neither guessable nor enumerable.
 */
final readonly class TranscriptionJob
{
    public function __construct(
        public int $id,
        public string $publicId,
        public int $uploadedByAdminId,
        public ?string $uploadedByUsername,
        public JobStatus $status,
        public ?ProcessingStage $stage,
        public string $originalFilename,
        public ?string $storedAudioPath,
        public ?string $retainedAudioPath,
        public ?float $durationSeconds,
        public ?string $transcript,
        public ?string $detectedLanguage,
        public ?string $errorMessage,
        public ?string $agentText,
        public ?string $customerText,
        public ?string $speakerSegmentsJson,
        public ?SpeakerSeparationStatus $speakerSeparationStatus,
        public ?string $speakerSeparationMethod,
        public ?float $speakerRoleConfidence,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $expiresAt,
    ) {}

    public function isDownloadable(): bool
    {
        return $this->status === JobStatus::COMPLETED && $this->transcript !== null;
    }

    /**
     * Whether the browser should keep polling. Terminal jobs stop it permanently — there is nothing
     * further to learn, and a page left open overnight should not keep hitting the server.
     */
    public function isPending(): bool
    {
        return $this->status->isActive();
    }

    /** Whether the source recording was kept after processing. Never exposes where it is. */
    public function hasRetainedRecording(): bool
    {
        return $this->retainedAudioPath !== null;
    }

    /** NULL `expires_at` means this conversation is kept indefinitely. */
    public function isKeptIndefinitely(): bool
    {
        return $this->expiresAt === null;
    }

    public function hasSeparatedText(): bool
    {
        return $this->agentText !== null || $this->customerText !== null;
    }
}
