<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure\Transcription;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\TranscriptText;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\Speaker\TranscriptToken;
use App\AudioToText\Domain\Transcription\AudioTranscriptionResult;
use App\AudioToText\Domain\Transcription\TranscriptionEngineInterface;
use App\AudioToText\Domain\Transcription\TranscriptionRequest;
use App\AudioToText\Domain\TranscriptionProvider;
use App\AudioToText\Infrastructure\Process\ProcessRunner;
use JsonException;

use function dirname;
use function file_get_contents;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Local whisper.cpp. **Console tier only.**
 *
 * Lifted out of `AudioTranscriber` without behavioural change: the same argv, the same best-effort
 * sidecar reading, the same exceptions. What changed is only that the checks which used to stop the
 * *worker* now stop *this engine* — see {@see assertReady()}.
 *
 * Runs for roughly 1.3x the recording's length on one CPU core, which is why nothing in the web tier
 * may reference this class. `WebTierCannotRunWhisperTest` fails the build if it is so much as named
 * under a `/Web/` path.
 */
final readonly class WhisperEngine implements TranscriptionEngineInterface
{
    /** Whisper writes `<stem>.txt` and `<stem>.json`; it is given the stem, never the filenames. */
    private const TRANSCRIPT_STEM = 'transcript';

    public function __construct(
        private AudioToTextSettings $settings,
        private ProcessRunner $processes,
    ) {}

    public function provider(): TranscriptionProvider
    {
        return TranscriptionProvider::Whisper;
    }

    public function isAvailable(): bool
    {
        return $this->settings->transcription->whisperIsUsable();
    }

    /**
     * The binary and the model, and nothing else.
     *
     * These two checks used to live in `AudioToTextSettings::problems()`, where a missing model stopped
     * the worker before it claimed *any* job — including Deepgram jobs that never needed whisper.cpp at
     * all. Here they fail one job, the worker continues, and the queue keeps moving.
     *
     * Filesystem inspection only: no process is started, so this is cheap enough to run per job and
     * before the recording is converted.
     */
    public function assertReady(): void
    {
        $problems = $this->settings->transcription->whisperProblems();

        if ($problems !== []) {
            throw AudioTranscriptionException::providerNotConfigured(
                $this->provider()->label(),
                implode(' ', $problems),
            );
        }
    }

    public function recognise(TranscriptionRequest $request): AudioTranscriptionResult
    {
        // Whisper writes its output beside the WAV, inside the workspace the orchestrator created and
        // will delete. Deriving the stem from the WAV rather than taking it as a parameter keeps the
        // engine's contract identical to Deepgram's, which needs no output location at all.
        $stem = dirname($request->wavPath) . '/' . self::TRANSCRIPT_STEM;

        $this->run($request->wavPath, $stem);

        return new AudioTranscriptionResult(
            $this->readTranscript($stem . '.txt'),
            $this->readLanguage($stem . '.json'),
            $this->readTokens($stem . '.json'),
        );
    }

    private function run(string $wav, string $outputStem): void
    {
        $result = $this->processes->run([
            $this->settings->transcription->whisperBinary,
            '-m', $this->settings->transcription->whisperModel,
            '-f', $wav,
            // Auto-detect rather than a pinned language: the deployment decides what it records, and
            // pinning the wrong one transcribes everything else as gibberish. Detection costs nothing
            // when the audio really is monolingual.
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
}
