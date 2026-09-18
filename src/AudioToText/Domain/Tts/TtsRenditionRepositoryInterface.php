<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

/**
 * Storage for AI audio renditions, and the concurrency rules that keep them from being paid for twice.
 *
 * Three of these methods are compare-and-swap operations rather than plain writes, and each one exists
 * because of a specific way money gets spent twice:
 *
 *  - {@see enqueue()} refuses a row that is already in flight, so a second click, a refresh, the
 *    automatic trigger and a role confirmation cannot stack up four generations of the same thing.
 *  - {@see claim()} moves exactly one worker into GENERATING, so two workers cannot both start.
 *  - {@see markReady()} and {@see markFailed()} compare an attempt token, so a slow worker whose row was
 *    re-enqueued underneath it cannot publish over the newer result.
 */
interface TtsRenditionRepositoryInterface
{
    public function findForJob(int $jobId, TtsOutputType $outputType): ?TtsRendition;

    /**
     * Every rendition belonging to the given jobs, keyed by job id and then by output type.
     *
     * Batched because a page shows a whole conversation: a separate upload has two children, and asking
     * per child would be two queries to render one screen.
     *
     * @param list<int> $jobIds
     *
     * @return array<int, array<string, TtsRendition>>
     */
    public function forJobs(array $jobIds): array;

    /**
     * Queue a generation, unless one is already outstanding or the request is a duplicate.
     *
     * The single place a paid attempt is authorised, and the only reason the five triggers that can ask
     * for one cannot produce five charges. It must **never** be written as a plain
     * `INSERT … ON DUPLICATE KEY UPDATE status='QUEUED'`: that resets a row a worker is in the middle of,
     * after which a second worker claims it and both generate, both bill, and both race to write the
     * filename.
     *
     * Rotates `attempt_token` whenever it does queue, which is what invalidates any older attempt still
     * running.
     *
     * @param string   $requestedHash the digest of the transcript to be spoken
     * @param int|null $adminId       the administrator who asked, or null when the upload's own request
     *                                is being honoured
     */
    public function enqueue(
        int $jobId,
        TtsOutputType $outputType,
        string $requestedHash,
        ?int $adminId,
        string $provider,
    ): TtsEnqueueOutcome;

    /**
     * Take the oldest queued rendition, atomically.
     *
     * FIFO by `id`, and the state change is a conditional update in the same statement that selects the
     * winner — so two workers running at once produce one claim and one null, never two claims.
     *
     * @param int $candidates how many rows to consider before giving up, so a poisoned head of the queue
     *                        cannot stall everything behind it
     */
    public function claim(int $candidates = 10): ?TtsRendition;

    /**
     * Publish a finished file.
     *
     * Guarded by `$attemptToken`: a worker whose row was re-enqueued while it was generating finds the
     * token has moved on and writes nothing, leaving the newer attempt to publish. Returns false in that
     * case so the caller can delete the file it just made rather than orphaning it.
     */
    public function markReady(
        int $id,
        string $attemptToken,
        string $fileName,
        string $fileHash,
        string $fileRenderKey,
        int $fileBytes,
        int $characterCount,
        int $requestCount,
        ?string $modelCustomer,
        ?string $modelAgent,
    ): bool;

    /**
     * Record a terminal failure, leaving `file_name` and `file_hash` untouched.
     *
     * Not clearing them is the point: a regeneration that fails must leave the audio it was replacing
     * exactly as playable as it was before anyone pressed the button.
     *
     * @param string $message already redacted, and short enough for a page
     */
    public function markFailed(int $id, string $attemptToken, string $message): bool;

    /**
     * Return rows abandoned mid-generation to a terminal state.
     *
     * Without this a worker killed by a deploy, the OOM killer or a systemd timeout leaves a row in
     * GENERATING forever — and because {@see claim()} only looks at QUEUED, nothing would ever pick it up
     * again, including the administrator, since {@see enqueue()} correctly refuses an in-flight row.
     *
     * Marks them FAILED and does **not** retry: the transcription worker's stale sweep gives the reason
     * — a crash may well repeat — and here each repetition would also be billed.
     *
     * @return int how many were recovered
     */
    public function recoverStale(int $olderThanSeconds): int;

    /**
     * Public ids of jobs that currently own a rendition directory.
     *
     * Used by the TTS worker's housekeeping to tell a live generation's working files from the leavings
     * of one that was killed.
     *
     * @return list<string>
     */
    public function generatingJobPublicIds(): array;

    public function countByStatus(TtsStatus $status): int;
}
