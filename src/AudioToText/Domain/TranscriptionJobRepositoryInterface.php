<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use App\AudioToText\Domain\Speaker\SpeakerSeparatedTranscript;
use Closure;
use DateTimeImmutable;

/**
 * Storage for transcription jobs.
 *
 * Two methods here are concurrency primitives rather than plain persistence, and their contracts matter
 * more than their signatures: {@see enqueueExclusively()} serialises the final admission checks, and
 * {@see claimNextQueued()} is the atomic claim that stops two workers taking the same row.
 */
interface TranscriptionJobRepositoryInterface
{
    public function findByPublicId(string $publicId): ?TranscriptionJob;

    public function findById(int $id): ?TranscriptionJob;

    /**
     * The global conversions list — every administrator's jobs, newest first.
     *
     * Deliberately not filtered by uploader: this is a shared administrator demo, and the uploader is
     * recorded for audit rather than for access control.
     *
     * @return list<TranscriptionJobListItem>
     */
    /**
     * One page of the global conversions list, newest first.
     *
     * @param int $offset rows to skip; 0 is the first page
     *
     * @return list<TranscriptionJobListItem>
     */
    public function recent(int $limit, int $previewLength, int $offset = 0): array;

    public function countAll(): int;

    /**
     * The four counters above both Audio-to-Text pages.
     *
     * Deliberately takes no cutoff: the window is a property of the summary itself
     * ({@see QueueSummary::WINDOW_HOURS}), not of the page rendering it. Passing it in meant two
     * callers each computed it, and both were wrong together.
     */
    public function summary(): QueueSummary;

    public function countActive(): int;

    /**
     * How many jobs are ahead of this one, counting from 1.
     *
     * Ordered by `id`, exactly as {@see claimNextQueued()} orders its scan, so the number the page
     * shows is the position the worker will actually take it in. Returns null for a job that is not
     * waiting.
     */
    public function queuePositionOf(int $id): ?int;


    /** Whether any job row — of any status — still owns this public id. */
    public function existsByPublicId(string $publicId): bool;

    /**
     * @return list<string>
     */
    public function activePublicIds(): array;

    /**
     * Runs `$work` while holding a database-wide named lock, so that the per-administrator re-check, the
     * global queue count and the INSERT cannot interleave with another request doing the same.
     *
     * Returns null when the lock itself could not be taken — the caller must treat that as "busy" and
     * refuse the upload, never as permission to proceed unserialised.
     *
     * @param Closure(): string $work
     */
    public function enqueueExclusively(Closure $work): ?string;

    public function create(
        string $publicId,
        int $uploadedByAdminId,
        string $originalFilename,
        string $storedAudioPath,
        ?float $durationSeconds,
        ?DateTimeImmutable $expiresAt,
        ?int $conversationId = null,
        ?SourceRole $sourceRole = null,
    ): string;

    /**
     * Atomically moves one QUEUED job to PROCESSING. Returns null when there was nothing to claim, or
     * when every candidate was taken by someone else between the scan and the update.
     */
    public function claimNextQueued(int $candidates = 10): ?TranscriptionJob;

    /** Best-effort telemetry; a failure here must never fail the job. */
    public function markStage(int $id, ProcessingStage $stage): void;

    /**
     * Commits the transcript the instant Whisper succeeds, while the job stays PROCESSING.
     *
     * This is what makes a crash during speaker separation survivable: stale recovery can see that a
     * transcript exists and complete the job rather than discarding a result that was already earned.
     */
    public function markTranscribed(int $id, string $transcript, ?string $detectedLanguage): void;

    /**
     * @param string|null $retainedAudioPath the recording moved into permanent storage, or null when
     *                                       there was nothing to retain
     */
    public function markCompleted(
        int $id,
        SpeakerSeparatedTranscript $separation,
        ?string $retainedAudioPath = null,
    ): void;

    /**
     * Complete a recording whose speaker was supplied rather than inferred.
     *
     * The transcript belongs entirely to one role, so it is copied into that role's column and the
     * other is left NULL. Every separation column stays NULL too: no diarization ran, no mapping was
     * scored, and writing `confidence = 1.0` would dress a fact we were told up as a measurement we
     * made — which is exactly the confusion `speaker_separation_status` exists to prevent.
     */
    public function markCompletedWithProvidedRole(
        int $id,
        SourceRole $sourceRole,
        ?string $retainedAudioPath = null,
    ): void;

    public function markFailed(int $id, string $userMessage): void;

    /**
     * Completes a job whose transcript survived but whose speaker separation did not — the crash-recovery
     * counterpart to {@see markCompleted()}.
     */
    public function markCompletedWithoutSeparation(int $id, SpeakerSeparationStatus $status): void;

    /**
     * @return list<TranscriptionJob>
     */
    public function findStale(int $staleAfterSeconds): array;

    /**
     * Terminal jobs whose retention window has passed.
     *
     * A NULL `expires_at` means the conversation is kept indefinitely and is never returned here, so
     * with `AUDIO_TRANSCRIPTION_RETENTION_SECONDS=0` this always yields nothing.
     *
     * @return list<TranscriptionJob>
     */
    public function findExpired(int $limit = 100): array;

    /**
     * Store a corrected conversation, guarded by the version the caller read.
     *
     * Writes reviewed columns only — the machine's `transcript`, `speaker_segments`, `agent_text` and
     * `customer_text` are never touched by a correction.
     *
     * @return bool false when `review_count` had moved on, so a concurrent correction is reported as a
     *              conflict rather than silently overwritten
     */
    public function saveReview(
        int $id,
        string $reviewedSegmentsJson,
        ?string $reviewedAgentText,
        ?string $reviewedCustomerText,
        int $reviewedByAdminId,
        int $expectedReviewCount,
    ): bool;

    /**
     * Record an explicit human confirmation of the speaker roles, publishing the two role columns.
     *
     * Kept separate from {@see saveReview()} because correcting a conversation and confirming who was
     * speaking are different acts — see `roles_confirmed_at`.
     *
     * The segments are written too, even when byte-identical to the machine's own. Confirming
     * establishes a reviewed layer: without one `isReviewed()` stays false, the effective-conversation
     * reader falls back to the raw columns, and the two role columns written here are never read — so
     * confirming an otherwise uncorrected conversation would change nothing anybody could see.
     */
    public function confirmRoles(
        int $id,
        string $segmentsJson,
        string $agentText,
        string $customerText,
        int $confirmedByAdminId,
        int $expectedReviewCount,
    ): bool;

    /** Drop the reviewed layer, returning the job to the machine's result. Same version guard. */
    public function clearReview(int $id, int $reviewedByAdminId, int $expectedReviewCount): bool;

    public function delete(int $id): void;
}
