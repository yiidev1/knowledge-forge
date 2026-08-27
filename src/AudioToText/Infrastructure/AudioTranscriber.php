<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\TranscriptText;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\Speaker\TranscriptToken;
use App\AudioToText\Infrastructure\Process\ProcessRunner;
use JsonException;

use function bin2hex;
use function file_get_contents;
use function filesize;
use function is_array;
use function is_dir;
use function is_executable;
use function is_file;
use function is_link;
use function is_readable;
use function is_string;
use function is_writable;
use function json_decode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sprintf;
use function trim;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * ffmpeg + whisper.cpp. **Console tier only.**
 *
 * The entry point takes a filesystem path and nothing else. There is deliberately no method here that
 * accepts an `UploadedFileInterface`, because the absence of an upload-shaped door is what stops a web
 * action ever being tempted to walk through one. A regression test walks every `src/*​/Web` directory
 * and fails the build if this class is so much as named there.
 *
 * The reason matters: transcription takes ninety-four seconds of one CPU core and 834 MB on this
 * hardware. Inside PHP-FPM that is a worker process held hostage, a request the browser gives up on,
 * and — with no queue in front of it — an unauthenticated-looking way to spend the machine.
 */
final readonly class AudioTranscriber
{
    private const CONVERTED_NAME = 'audio.wav';
    private const TRANSCRIPT_STEM = 'transcript';

    public function __construct(
        private AudioToTextSettings $settings,
        private ProcessRunner $processes,
    ) {}

    /**
     * Converts and transcribes, then hands the workspace to `$onWorkspaceReady` so the caller can run
     * further analysis over the same 16 kHz WAV before it is deleted.
     *
     * The source file belongs to the caller: this reads it and never removes it. The workspace it
     * creates for itself is removed in `finally`, on every path.
     *
     * @param (callable(string): void)|null                         $onStage          stage name, best-effort
     * @param (callable(string, AudioTranscriptionResult): void)|null $onWorkspaceReady receives the WAV path
     */
    public function transcribeFile(
        string $sourcePath,
        ?callable $onStage = null,
        ?callable $onWorkspaceReady = null,
    ): AudioTranscriptionResult {
        $this->assertToolchainPresent();

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw AudioTranscriptionException::uploadUnreadable(
                sprintf('the queued audio is missing or unreadable at "%s"', $sourcePath),
            );
        }

        $workspace = $this->createWorkspace();

        try {
            if ($onStage !== null) {
                $onStage('CONVERTING');
            }

            $wav = $workspace . '/' . self::CONVERTED_NAME;
            $this->convert($sourcePath, $wav);

            if ($onStage !== null) {
                $onStage('TRANSCRIBING');
            }

            $stem = $workspace . '/' . self::TRANSCRIPT_STEM;
            $this->recognise($wav, $stem);

            $result = new AudioTranscriptionResult(
                $this->readTranscript($stem . '.txt'),
                $this->readLanguage($stem . '.json'),
                $this->readTokens($stem . '.json'),
            );

            // Speaker separation runs here, while the converted WAV still exists. Diarizing the same
            // file whisper transcribed is what lets the two timelines be aligned without an offset.
            if ($onWorkspaceReady !== null) {
                $onWorkspaceReady($wav, $result);
            }

            return $result;
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    private function assertToolchainPresent(): void
    {
        if (!is_file($this->settings->transcription->ffmpegBinary) || !is_executable($this->settings->transcription->ffmpegBinary)) {
            throw AudioTranscriptionException::ffmpegMissing($this->settings->transcription->ffmpegBinary);
        }

        if (!is_file($this->settings->transcription->whisperBinary) || !is_executable($this->settings->transcription->whisperBinary)) {
            throw AudioTranscriptionException::whisperMissing($this->settings->transcription->whisperBinary);
        }

        if (!is_file($this->settings->transcription->whisperModel) || !is_readable($this->settings->transcription->whisperModel)) {
            throw AudioTranscriptionException::modelMissing($this->settings->transcription->whisperModel);
        }
    }

    private function createWorkspace(): string
    {
        $base = $this->settings->transcription->temporaryDirectory;

        if (!is_dir($base) && !@mkdir($base, 0o750, true) && !is_dir($base)) {
            throw AudioTranscriptionException::temporaryDirectoryNotWritable($base, 'it could not be created');
        }

        if (!is_writable($base)) {
            throw AudioTranscriptionException::temporaryDirectoryNotWritable(
                $base,
                'it is not writable by the worker user',
            );
        }

        $workspace = $base . '/' . bin2hex(random_bytes(16));
        if (!@mkdir($workspace, 0o700, true) && !is_dir($workspace)) {
            throw AudioTranscriptionException::temporaryDirectoryNotWritable(
                $workspace,
                'the per-run directory could not be created',
            );
        }

        return $workspace;
    }

    /**
     * Normalises to exactly what whisper.cpp wants: 16 kHz, mono, signed 16-bit little-endian PCM.
     *
     * Not optional. whisper.cpp will not resample for you, and the sample used to validate this feature
     * is 8 kHz telephone audio — handing it over unconverted produces silence or nonsense.
     */
    private function convert(string $source, string $destination): void
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

    private function recognise(string $wav, string $outputStem): void
    {
        $result = $this->processes->run([
            $this->settings->transcription->whisperBinary,
            '-m', $this->settings->transcription->whisperModel,
            '-f', $wav,
            // Auto-detect, with the multilingual model: these recordings mix English, Spanish, Gujarati
            // and Hindi, and pinning a language transcribes the rest of it as gibberish.
            '-l', 'auto',
            '-t', (string) $this->settings->transcription->threads,
            '-np',                   // no progress animation on stdout
            '-otxt',                 // the transcript shown and downloaded
            '-oj',                   // JSON sidecar, read for the detected language
            '-ojf',                  // token-level timestamps, read for speaker alignment
            '-of', $outputStem,
        ], $this->settings->transcription->timeoutSeconds);

        if ($result->timedOut) {
            throw AudioTranscriptionException::transcriptionTimedOut($this->settings->transcription->timeoutSeconds);
        }

        if (!$result->isSuccessful()) {
            throw AudioTranscriptionException::transcriptionFailed($result->diagnostics());
        }
    }

    private function readTranscript(string $path): string
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw AudioTranscriptionException::transcriptMissing($path);
        }

        $text = trim($raw);
        if ($text === '') {
            throw AudioTranscriptionException::emptyTranscript();
        }

        return TranscriptText::toValidUtf8($text);
    }

    /**
     * Best effort by design: any problem yields null.
     *
     * The language is a badge on the page. A future whisper.cpp release renaming this field should cost
     * that badge and nothing else — certainly not a transcript that took ninety-four seconds to produce.
     */
    private function readLanguage(string $path): ?string
    {
        $decoded = $this->decodeJson($path);
        if ($decoded === null) {
            return null;
        }

        $result = $decoded['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }

        $language = $result['language'] ?? null;

        return is_string($language) && $language !== '' ? $language : null;
    }

    /**
     * Token-level timings from `-ojf`.
     *
     * Also best effort. Without them speaker separation reports a failure and the full transcript is
     * still stored and downloadable, which is exactly the degradation this feature is built around.
     *
     * @return list<TranscriptToken>
     */
    private function readTokens(string $path): array
    {
        $decoded = $this->decodeJson($path);
        if ($decoded === null) {
            return [];
        }

        $segments = $decoded['transcription'] ?? null;
        if (!is_array($segments)) {
            return [];
        }

        $tokens = [];
        foreach ($segments as $segment) {
            if (!is_array($segment) || !is_array($segment['tokens'] ?? null)) {
                continue;
            }

            foreach ($segment['tokens'] as $token) {
                if (!is_array($token)) {
                    continue;
                }

                $text = $token['text'] ?? null;
                $offsets = $token['offsets'] ?? null;

                if (!is_string($text) || !is_array($offsets)) {
                    continue;
                }

                $from = $offsets['from'] ?? null;
                $to = $offsets['to'] ?? null;

                if (!is_int($from) || !is_int($to)) {
                    continue;
                }

                $tokens[] = new TranscriptToken($from, $to, TranscriptText::toValidUtf8($text));
            }
        }

        return $tokens;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decodeJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Flat by construction — the workspace holds files, never subdirectories — so this does not recurse.
     */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = @scandir($directory);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $directory . '/' . $entry;
                if (is_file($path) || is_link($path)) {
                    @unlink($path);
                }
            }
        }

        @rmdir($directory);
    }
}
