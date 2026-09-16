<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure\Transcription;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Infrastructure\Process\ProcessRunner;

use function filesize;
use function is_executable;
use function is_file;

/**
 * ffmpeg: whatever was uploaded in, 16 kHz mono signed 16-bit PCM out. **Console tier only.**
 *
 * Extracted from `AudioTranscriber` unchanged — same argv, same checks, same exceptions — because it
 * stopped being a Whisper detail the moment a second engine existed. Every provider gets the same
 * normalised bytes, and so does the diarizer afterwards; that shared file is what lets the transcript
 * and the speaker timeline be aligned with no offset between them.
 *
 * whisper.cpp will not resample for you, and 8 kHz telephone audio handed over unconverted produces
 * silence or nonsense. Deepgram would accept the original file, but is given the same WAV anyway: one
 * normalisation step means one thing to reason about when timings disagree, and the diarizer needs the
 * WAV regardless.
 */
final readonly class AudioNormalizer
{
    public function __construct(
        private AudioToTextSettings $settings,
        private ProcessRunner $processes,
    ) {}

    /**
     * @throws AudioTranscriptionException when ffmpeg is absent
     */
    public function assertAvailable(): void
    {
        $binary = $this->settings->transcription->ffmpegBinary;

        if (!is_file($binary) || !is_executable($binary)) {
            throw AudioTranscriptionException::ffmpegMissing($binary);
        }
    }

    /**
     * @throws AudioTranscriptionException on timeout, a non-zero exit, or an empty output file
     */
    public function normalise(string $source, string $destination): void
    {
        $result = $this->processes->run([
            $this->settings->transcription->ffmpegBinary,
            '-nostdin',              // never block waiting for a keypress that will never come
            '-hide_banner',
            '-loglevel', 'error',
            '-y',
            '-threads', '1',         // the whole pipeline is pinned to one core
            '-i', $source,
            '-ar', '16000',
            '-ac', '1',
            '-c:a', 'pcm_s16le',
            $destination,
        ], $this->settings->transcription->timeoutSeconds);

        if ($result->timedOut) {
            throw AudioTranscriptionException::conversionTimedOut($this->settings->transcription->timeoutSeconds);
        }

        $size = is_file($destination) ? @filesize($destination) : false;

        // ffmpeg occasionally exits 0 having written nothing useful. An empty output would go on to
        // produce an empty transcript, which reads like "no speech detected" rather than a conversion
        // failure — a much harder thing to diagnose from the log a week later.
        if (!$result->isSuccessful() || $size === false || $size === 0) {
            throw AudioTranscriptionException::conversionFailed($result->diagnostics());
        }
    }
}
