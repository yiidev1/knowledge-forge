<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use RuntimeException;
use Throwable;

use function ceil;
use function intdiv;
use function sprintf;

/**
 * Carries two messages, and the separation is the whole point.
 *
 * `getMessage()` is written for the person who uploaded the file. It is what the page shows and what is
 * stored in `error_message`, so it must never contain a filesystem path, an exit code, a command line
 * or a fragment of stderr.
 *
 * `technicalDetail()` is written for whoever is reading the log at 2am. It may contain all of those.
 *
 * Keeping both on one object is what stops the usual drift where a developer, needing the detail,
 * quietly widens the user-facing string until it leaks the server's layout.
 */
final class AudioTranscriptionException extends RuntimeException
{
    private function __construct(
        string $userMessage,
        private readonly string $technicalDetail,
        ?Throwable $previous = null,
    ) {
        parent::__construct($userMessage, 0, $previous);
    }

    /** Safe for the log only. Never render this. */
    public function technicalDetail(): string
    {
        return $this->technicalDetail === '' ? $this->getMessage() : $this->technicalDetail;
    }

    // ---------------------------------------------------------------- queue admission

    /**
     * The machine-wide queue is full, or the enqueue lock was refused.
     *
     * Not a per-administrator limit: anyone may queue as many recordings as they like. This is the
     * global cap (`AUDIO_TRANSCRIPTION_MAX_QUEUE`), which is disabled by default.
     */
    public static function queueFull(int $maxQueue): self
    {
        return new self(
            'The transcription service is currently busy. Please try again later.',
            sprintf(
                'machine-wide queue limit of %d QUEUED+PROCESSING jobs reached, or the enqueue lock was refused',
                $maxQueue,
            ),
        );
    }

    /**
     * @param string $maxLabel the cap as a person would say it, from
     *                         {@see \App\AudioToText\Application\Settings\TranscriptionSettings::maxDurationLabel()}
     *                         — so raising the setting rewords this message with nothing to edit here
     */
    public static function tooLong(float $seconds, int $maxSeconds, string $maxLabel): self
    {
        return new self(
            sprintf(
                'That recording is %s long. The maximum allowed duration is %s — please try a shorter one.',
                self::durationLabel((int) ceil($seconds)),
                $maxLabel,
            ),
            sprintf('duration %.2fs exceeds the %ds limit', $seconds, $maxSeconds),
        );
    }

    /**
     * The rejected recording's own length, phrased to match the limit it broke.
     *
     * "That recording is 512 seconds long. The maximum allowed duration is 5 minutes" invites the reader
     * to do arithmetic before they can act on it; both halves in minutes does not.
     */
    private static function durationLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ($seconds === 1 ? ' second' : ' seconds');
        }

        $minutes = intdiv($seconds, 60);
        $remainder = $seconds % 60;
        $label = $minutes . ($minutes === 1 ? ' minute' : ' minutes');

        return $remainder === 0
            ? $label
            : $label . ' ' . $remainder . ($remainder === 1 ? ' second' : ' seconds');
    }

    public static function uploadUnreadable(string $detail): self
    {
        return new self(
            'That upload could not be read. Please try selecting the file again.',
            $detail,
        );
    }

    public static function durationUnknown(string $detail): self
    {
        return new self(
            'The length of that recording could not be determined. '
            . 'It may be corrupted, or it may not contain audio.',
            $detail,
        );
    }

    // ---------------------------------------------------------------- toolchain

    public static function ffmpegMissing(string $path): self
    {
        return new self(
            'Audio conversion is not available on this server.',
            sprintf('ffmpeg is not an executable file at "%s"', $path),
        );
    }

    public static function ffprobeMissing(string $path): self
    {
        return new self(
            'Audio inspection is not available on this server.',
            sprintf('ffprobe is not an executable file at "%s"', $path),
        );
    }

    public static function whisperMissing(string $path): self
    {
        return new self(
            'Speech recognition is not available on this server.',
            sprintf('whisper-cli is not an executable file at "%s"', $path),
        );
    }

    public static function modelMissing(string $path): self
    {
        return new self(
            'The speech recognition model has not been installed.',
            sprintf('the whisper model is not a readable file at "%s"', $path),
        );
    }

    public static function temporaryDirectoryNotWritable(string $path, string $reason): self
    {
        return new self(
            'The server has nowhere to put temporary audio files.',
            sprintf('temporary directory "%s": %s', $path, $reason),
        );
    }

    // ---------------------------------------------------------------- processing

    public static function conversionFailed(string $diagnostics): self
    {
        return new self('The audio could not be converted.', 'ffmpeg failed: ' . $diagnostics);
    }

    public static function conversionTimedOut(int $seconds): self
    {
        return new self(
            'The audio could not be converted.',
            sprintf('ffmpeg exceeded the %ds timeout', $seconds),
        );
    }

    public static function transcriptionFailed(string $diagnostics): self
    {
        return new self('The audio could not be transcribed.', 'whisper-cli failed: ' . $diagnostics);
    }

    public static function transcriptionTimedOut(int $seconds): self
    {
        return new self(
            'The audio could not be transcribed.',
            sprintf('whisper-cli exceeded the %ds timeout', $seconds),
        );
    }

    public static function transcriptMissing(string $path): self
    {
        return new self(
            'The audio could not be transcribed.',
            sprintf('whisper-cli produced no readable transcript at "%s"', $path),
        );
    }

    public static function emptyTranscript(): self
    {
        return new self(
            'No speech was detected in that recording.',
            'whisper-cli returned an empty transcript',
        );
    }

    // ---------------------------------------------------------------- recovery

    // ---------------------------------------------------------------- provider selection

    /**
     * The engine this job was queued with cannot run on this machine.
     *
     * Raised from `assertReady()`, **before any audio is converted**, so a misconfigured provider costs
     * nothing and fails only its own jobs. The reason is local configuration — a missing binary, an
     * absent key — never a failed call to the provider.
     *
     * The user-facing half names the provider and nothing else: which file is missing, or that a
     * credential is unset, is the operator's business and belongs in the log.
     */
    public static function providerNotConfigured(string $providerLabel, string $detail): self
    {
        return new self(
            sprintf(
                '%s is not configured on this server, so this recording could not be transcribed. '
                    . 'An administrator needs to finish setting it up.',
                $providerLabel,
            ),
            sprintf('provider %s is not ready: %s', $providerLabel, $detail),
        );
    }

    /**
     * Deepgram answered, and the answer was not a transcript.
     *
     * Covers every HTTP status Deepgram can refuse with. The status and its body go to the log; the
     * administrator is told that the service refused the recording, because the distinction between
     * 401 and 429 is not one they can act on from the page.
     */
    public static function deepgramRejected(int $status, string $detail): self
    {
        $user = match (true) {
            $status === 401 || $status === 403
                => 'Deepgram rejected the credentials for this server. An administrator needs to check the '
                    . 'Deepgram configuration.',
            $status === 429
                => 'Deepgram is rate-limiting this server, so the recording could not be transcribed. '
                    . 'Try again shortly.',
            $status >= 500
                => 'Deepgram is temporarily unavailable, so the recording could not be transcribed. '
                    . 'Try again shortly.',
            default
            => 'Deepgram could not transcribe this recording.',
        };

        return new self($user, sprintf('Deepgram returned HTTP %d: %s', $status, $detail));
    }

    /** The request never reached Deepgram — DNS, TLS, connection refused, timeout. */
    public static function deepgramUnreachable(string $detail): self
    {
        return new self(
            'Deepgram could not be reached from this server, so the recording could not be transcribed.',
            sprintf('Deepgram transport failure: %s', $detail),
        );
    }

    /** A 200 whose body was not the shape the pipeline needs. */
    public static function deepgramUnreadable(string $detail): self
    {
        return new self(
            'Deepgram returned a response this server could not read, so the recording could not be '
                . 'transcribed.',
            sprintf('Deepgram response unusable: %s', $detail),
        );
    }

    public static function interrupted(int $staleAfterSeconds): self
    {
        return new self(
            'Transcription was interrupted before it finished. Please try again.',
            sprintf('job was PROCESSING for more than %ds without completing', $staleAfterSeconds),
        );
    }

    public static function unexpected(): self
    {
        return new self(
            'Something went wrong while transcribing that recording.',
            'unexpected error; see the preceding log entry',
        );
    }
}
