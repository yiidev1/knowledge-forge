<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Settings;

use App\AudioToText\Application\AudioUploadValidator;

use function implode;
use function intdiv;
use function number_format;
use function rtrim;

/**
 * The transcription half of {@see \App\AudioToText\Application\AudioToTextSettings}.
 *
 * Grouped rather than flattened so the one settings object stays readable, but this is never injected
 * on its own — services depend on `AudioToTextSettings` and reach `->transcription`. One injected type
 * for the whole module means adding a setting never changes a constructor signature anywhere.
 */
final readonly class TranscriptionSettings
{
    private const BYTES_PER_MEGABYTE = 1048576;
    private const SECONDS_PER_MINUTE = 60;

    public function __construct(
        public string $ffmpegBinary,
        public string $ffprobeBinary,
        public string $whisperBinary,
        public string $whisperModel,
        public string $temporaryDirectory,
        public int $maxUploadBytes,
        public int $maxDurationSeconds,
        public int $timeoutSeconds,
        public int $threads,
        public int $maxQueue,
        public int $retentionSeconds,
        public int $staleAfterSeconds,
        public int $workerSleepSeconds,
    ) {}

    /**
     * "15 MB", or "15.5 MB" when the limit is not a round number.
     *
     * Sub-megabyte limits fall back to KB rather than rounding to "0 MB", which would otherwise tell
     * someone their file is over a zero-byte limit — a message that reads as a bug rather than a rule.
     */
    public function maxUploadLabel(): string
    {
        if ($this->maxUploadBytes < self::BYTES_PER_MEGABYTE) {
            return rtrim(rtrim(number_format($this->maxUploadBytes / 1024, 1, '.', ''), '0'), '.') . ' KB';
        }

        $megabytes = number_format($this->maxUploadBytes / self::BYTES_PER_MEGABYTE, 1, '.', '');

        return rtrim(rtrim($megabytes, '0'), '.') . ' MB';
    }

    /**
     * Whether a probed recording length is within the cap.
     *
     * The boundary is inclusive: exactly `maxDurationSeconds` is accepted, anything beyond it is not.
     * Kept here rather than written as a comparison at the call site so the rule and the number it
     * depends on cannot drift apart, and so the boundary is testable without ffprobe.
     *
     * Takes the *probed* length. Nothing the browser claims about the file is consulted — a client can
     * put any duration in an upload, and the only trustworthy answer comes from reading the media.
     */
    public function allowsDuration(float $probedSeconds): bool
    {
        return $probedSeconds <= (float) $this->maxDurationSeconds;
    }

    /**
     * The duration cap in the units a person would say it in.
     *
     * Derived rather than written down anywhere, so raising `AUDIO_TRANSCRIPTION_MAX_DURATION` to 600
     * changes the upload hint and the rejection message to "10 minutes" on its own — no template or
     * message string to remember. Whole minutes read as "5 minutes"; anything else keeps the seconds
     * ("4 minutes 30 seconds"), because rounding a limit is how an off-by-thirty-seconds rejection
     * ends up looking like a bug.
     */
    public function maxDurationLabel(): string
    {
        $seconds = $this->maxDurationSeconds;

        if ($seconds < self::SECONDS_PER_MINUTE) {
            return $seconds . ($seconds === 1 ? ' second' : ' seconds');
        }

        $minutes = intdiv($seconds, self::SECONDS_PER_MINUTE);
        $remainder = $seconds % self::SECONDS_PER_MINUTE;
        $label = $minutes . ($minutes === 1 ? ' minute' : ' minutes');

        if ($remainder === 0) {
            return $label;
        }

        return $label . ' ' . $remainder . ($remainder === 1 ? ' second' : ' seconds');
    }

    /**
     * Whether the machine-wide queue cap is switched off.
     *
     * `AUDIO_TRANSCRIPTION_MAX_QUEUE = 0` means an administrator may queue as many recordings as they
     * like — the default. The cap, when set, counts QUEUED plus PROCESSING jobs across the whole
     * installation; it is never per administrator.
     */
    public function hasUnlimitedQueue(): bool
    {
        return $this->maxQueue <= 0;
    }

    /**
     * Whether completed jobs are kept forever.
     *
     * `AUDIO_TRANSCRIPTION_RETENTION_SECONDS = 0` means indefinitely, and that is this project's
     * default: the conversations are the point, not a by-product. They are intended to be read back
     * later by other work, so nothing deletes them on a timer unless an operator asks for it.
     */
    public function retainsIndefinitely(): bool
    {
        return $this->retentionSeconds <= 0;
    }

    /** Null when retention is indefinite — for a template that has to say how long things are kept. */
    public function retentionHours(): ?int
    {
        if ($this->retainsIndefinitely()) {
            return null;
        }

        return max(1, (int) round($this->retentionSeconds / 3600));
    }

    /**
     * The **temporary** processing workspace: one directory per job, deleted when the job ends, and
     * swept by the orphan collector if a job dies without cleaning up.
     *
     * Nothing here survives a job. Retained recordings live in {@see recordingsDirectory()}, which the
     * sweeper never touches.
     */
    public function jobsDirectory(): string
    {
        return rtrim($this->temporaryDirectory, '/') . '/jobs';
    }

    /**
     * The **permanent** store for source recordings of successful jobs.
     *
     * Deliberately a sibling of `jobs/` rather than a subdirectory: the orphan sweep walks `jobs/` and
     * deletes what it finds, so a retained recording inside it would be one bug away from being
     * collected. Keeping them in separate trees makes "the sweeper cannot reach retained audio" a fact
     * about the layout rather than a rule someone has to remember.
     *
     * Still under `runtime/`, so still outside the web root and never reachable over HTTP.
     */
    public function recordingsDirectory(): string
    {
        return rtrim($this->temporaryDirectory, '/') . '/recordings';
    }

    /**
     * Beside `jobs/`, never inside it — the orphan sweep must not be able to delete the file that
     * guarantees single-worker operation.
     */
    public function workerLockFile(): string
    {
        return rtrim($this->temporaryDirectory, '/') . '/worker.lock';
    }

    /**
     * How often the job page polls, in seconds. Never faster than every two seconds: polling more often
     * than the worker's own idle sleep only adds load without learning anything sooner.
     */
    public function pollSeconds(): int
    {
        return max(2, $this->workerSleepSeconds);
    }

    public function allowedExtensionList(): string
    {
        $labels = [];
        foreach (AudioUploadValidator::EXTENSIONS as $extension) {
            $labels[] = '.' . $extension;
        }

        return implode(', ', $labels);
    }
}
