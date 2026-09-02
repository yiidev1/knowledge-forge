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
        // --- reviewed layer -------------------------------------------------------------------
        // Null on every job the pipeline produced. A correction adds this layer; it never rewrites the
        // raw columns above, so "Yes. For pikup" stays in `transcript` while the reviewed conversation
        // carries "Yes. For pickup".
        public ?string $reviewedSegmentsJson = null,
        public ?string $reviewedAgentText = null,
        public ?string $reviewedCustomerText = null,
        public ?DateTimeImmutable $reviewedAt = null,
        /** Joined from `admin_users` — the numeric reviewer id is never exposed. */
        public ?string $reviewedByUsername = null,
        /**
         * When an administrator explicitly confirmed who the speakers are.
         *
         * Separate from `reviewedAt` on purpose: correcting a turn boundary is not the same act as
         * asserting both speakers' identities, and a NEEDS_REVIEW call must not gain confirmed roles
         * because somebody fixed a boundary in it.
         */
        public ?DateTimeImmutable $rolesConfirmedAt = null,
        /** Also the optimistic-lock version, as `messages.edit_count` is for chat edits. */
        public int $reviewCount = 0,
        /**
         * The upload this recording belongs to.
         *
         * Every job has one — the migration back-filled a conversation for each pre-existing row — but
         * the property stays nullable so a job read through an older code path is still constructible.
         */
        public ?int $conversationId = null,
        /**
         * What this recording holds, as the administrator described it.
         *
         * `Common` means the speakers still have to be worked out. `Customer` or `Agent` means they do
         * not, and the worker skips diarization and role mapping entirely for this job.
         */
        public ?SourceRole $sourceRole = null,
    ) {}

    /** Whether an administrator has corrected this conversation. */
    public function isReviewed(): bool
    {
        return $this->reviewedSegmentsJson !== null;
    }

    /**
     * Whether Agent/Customer may be stated as fact for this conversation.
     *
     * Either the machine cleared its own gates — a published split, which needs no further ceremony —
     * or a person said so explicitly. Nothing else counts, and in particular the mere existence of a
     * reviewed layer does not: fixing a boundary is not confirming an identity.
     */
    public function rolesConfirmed(): bool
    {
        if ($this->rolesConfirmedAt !== null) {
            return true;
        }

        return $this->speakerSeparationStatus?->isPublishable() === true
            && ($this->agentText !== null || $this->customerText !== null);
    }

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
