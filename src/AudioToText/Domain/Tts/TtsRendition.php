<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

use DateTimeImmutable;

/**
 * One AI audio output for one recording: what exists, what was asked for, and how the two differ.
 *
 * ## Two hashes, because they answer different questions
 *
 * `$requestedHash` is the transcript the latest attempt aimed at. `$fileHash` is the transcript the file
 * actually on disk was made from. Keeping both is what lets a failed regeneration report itself honestly
 * without disowning the good audio it was trying to replace — the row can say "the last attempt was for
 * a newer transcript and it failed" while still handing out the previous file.
 *
 * ## The render key is not the hash
 *
 * Changing a voice, the sample rate or the chunk size changes the audio without changing a single word.
 * That is a different sentence to show somebody — "this was made with a different voice setting" — and
 * folding it into the transcript hash would make every voice change announce itself as "your transcript
 * changed", which is false and would train administrators to ignore the notice that matters.
 *
 * ## Playability is a property of the file, not of the status
 *
 * {@see isPlayable()} asks whether there are bytes on disk. A FAILED row with a file is the normal
 * outcome of a regeneration that did not work, and it must still play.
 */
final readonly class TtsRendition
{
    public function __construct(
        public int $id,
        public int $jobId,
        public TtsOutputType $outputType,
        public TtsStatus $status,
        /** Rotated on every enqueue; the worker carries it so a superseded attempt cannot publish. */
        public string $attemptToken,
        public string $requestedHash,
        public ?string $fileName,
        public ?string $fileHash,
        public ?string $fileRenderKey,
        public ?int $fileBytes,
        public ?int $characterCount,
        public ?int $requestCount,
        public string $provider,
        public ?string $modelCustomer,
        public ?string $modelAgent,
        public int $attempts,
        public ?string $errorMessage,
        public ?int $requestedByAdminId,
        public ?string $requestedByUsername,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $generatingSince,
        public ?DateTimeImmutable $generatedAt,
    ) {}

    /** Whether there is audio to hand out, whatever the latest attempt did. */
    public function isPlayable(): bool
    {
        return $this->fileName !== null;
    }

    /**
     * Whether the audio on disk was made from the transcript that is current now.
     *
     * The question the "Transcript changed after this audio was generated" notice is derived from. A
     * rendition with no file is not stale — it is absent, which is a different thing and reads differently.
     */
    public function matchesTranscript(string $currentHash): bool
    {
        return $this->fileHash !== null && $this->fileHash === $currentHash;
    }

    public function isStale(string $currentHash): bool
    {
        return $this->isPlayable() && !$this->matchesTranscript($currentHash);
    }

    /**
     * Whether the audio was produced with the voice and format configured now.
     *
     * Reported separately from staleness, and never as a reason to regenerate automatically: the words
     * are still right, so this is an offer rather than a warning.
     */
    public function matchesRenderKey(string $currentKey): bool
    {
        return $this->fileRenderKey !== null && $this->fileRenderKey === $currentKey;
    }

    /**
     * Whether generating again would produce the same bytes, and so must not be paid for again.
     *
     * Both halves are required: the same words rendered by a different voice is genuinely different
     * audio, and the same voice reading different words obviously is.
     */
    public function isCurrent(string $currentHash, string $currentKey): bool
    {
        return $this->status === TtsStatus::Ready
            && $this->matchesTranscript($currentHash)
            && $this->matchesRenderKey($currentKey);
    }

    /**
     * Whether an automatic retry is still permitted.
     *
     * Every attempt is billed, so this is a spend limit rather than a reliability setting: past the cap a
     * person has to decide that another one is worth it.
     */
    public function mayRetryAutomatically(int $maxAttempts): bool
    {
        return $this->attempts < $maxAttempts;
    }
}
