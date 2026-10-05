<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use App\AudioToText\Domain\Speaker\SeparationReviewReason;
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
        /** The workspace copy, or null for a recording retained at download without a transcript asked for. */
        ?string $storedAudioPath,
        ?float $durationSeconds,
        ?DateTimeImmutable $expiresAt,
        ?int $conversationId = null,
        ?SourceRole $sourceRole = null,
        ?TranscriptionProvider $transcriptionProvider = null,
        /** Where this row starts. Defaulted, so every existing caller is unchanged. */
        JobStatus $status = JobStatus::QUEUED,
        /** The permanent copy, when a download already made one. Normally the worker writes it. */
        ?string $retainedAudioPath = null,
    ): string;

    /**
     * Atomically moves one QUEUED job to PROCESSING. Returns null when there was nothing to claim, or
     * when every candidate was taken by someone else between the scan and the update.
     */
    public function claimNextQueued(int $candidates = 10): ?TranscriptionJob;

    /**
     * Ask for a transcript of a recording that was downloaded without one.
     *
     * One conditional UPDATE whose affected-row count is the answer, so a second press changes nothing.
     * The provider is captured at this moment rather than at download — see the implementation.
     *
     * @return bool whether this call is the one that requested it
     */
    public function requestTranscription(int $id, TranscriptionProvider $provider): bool;

    /**
     * Note that a workspace copy of a retained recording now exists.
     *
     * Written by the worker, immediately after it copies one back, so a run that dies mid-transcription
     * does not leave the row claiming there is no workspace file while one is sitting on disk. The
     * orphan sweep and the next attempt both read this column.
     */
    public function recordWorkspaceCopy(int $id, string $storedName): void;

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
        /**
         * The speaker's own utterances, already JSON-encoded, or null when the engine's timings could
         * not be measured. A single-speaker recording has no exchange to discover, but it does have
         * pauses, and those are what separate one message from the next.
         */
        ?string $segmentsJson = null,
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

    /**
     * Completed recordings left for speaker review that carry no diagnosis yet.
     *
     * For `kf:audio:diagnose-speaker-review` alone. Newest first, bounded, and narrowed to the one
     * state the diagnosis describes — a recording that was published, failed outright or already has an
     * answer is not asked about again.
     *
     * @return list<TranscriptionJob>
     */
    public function needingSpeakerReviewDiagnosis(int $limit): array;

    /**
     * Record why one recording was left for speaker review.
     *
     * Writes `speaker_review_reason` and nothing else — not a status, not a confidence, not a role, not
     * a segment. It cannot move a recording between COMPLETED and NEEDS_REVIEW, which is what makes
     * running it against live data safe.
     */
    public function recordSpeakerReviewDiagnosis(int $id, SeparationReviewReason $reason): void;
}
