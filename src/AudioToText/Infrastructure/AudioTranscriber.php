<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\TranscriberResolver;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\Transcription\AudioTranscriptionResult;
use App\AudioToText\Domain\Transcription\DeepgramKeyterms;
use App\AudioToText\Domain\Transcription\TranscriptionRequest;
use App\AudioToText\Domain\TranscriptionProvider;
use App\AudioToText\Infrastructure\Transcription\AudioNormalizer;

use function bin2hex;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function is_writable;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sprintf;
use function unlink;

/**
 * Orchestrates one transcription: workspace, normalisation, recognition, cleanup. **Console tier only.**
 *
 * The entry point takes a filesystem path and nothing else. There is deliberately no method here that
 * accepts an `UploadedFileInterface`, because the absence of an upload-shaped door is what stops a web
 * action ever being tempted to walk through one. A regression test walks every `src/*​/Web` directory
 * and fails the build if this class is so much as named there.
 *
 * The reason matters: local transcription takes ninety-four seconds of one CPU core and 834 MB on this
 * hardware, and the cloud alternative blocks on a remote service. Inside PHP-FPM either is a worker
 * process held hostage, a request the browser gives up on, and — with no queue in front of it — an
 * unauthenticated-looking way to spend the machine.
 *
 * ## What lives here, and what does not
 *
 * Recognition moved behind {@see \App\AudioToText\Domain\Transcription\TranscriptionEngineInterface}
 * when the provider became selectable. What is left is everything that is true of *every* provider: the
 * temporary directory and its guaranteed removal, the ffmpeg normalisation both the engine and the
 * diarizer consume, the stage callbacks the job page polls, and the workspace handover that lets
 * speaker separation run against the same WAV.
 *
 * ## Readiness precedes work
 *
 * `assertReady()` runs **before** the workspace is created and before ffmpeg starts. A job queued for a
 * provider this machine cannot run therefore fails having spent nothing, and — because the check is
 * per engine — it fails alone: the worker claims the next job as usual, and jobs for the other provider
 * are entirely unaffected.
 */
final readonly class AudioTranscriber
{
    private const CONVERTED_NAME = 'audio.wav';

    public function __construct(
        private AudioToTextSettings $settings,
        private TranscriberResolver $engines,
        private AudioNormalizer $normalizer,
    ) {}

    /**
     * Normalises and transcribes, then hands the workspace to `$onWorkspaceReady` so the caller can run
     * further analysis over the same 16 kHz WAV before it is deleted.
     *
     * The source file belongs to the caller: this reads it and never removes it. The workspace it
     * creates for itself is removed in `finally`, on every path.
     *
     * @param TranscriptionProvider                                  $provider         as persisted on the
     *                                                                                 job at enqueue —
     *                                                                                 never re-read from
     *                                                                                 the global default
     * @param (callable(string): void)|null                          $onStage          stage name, best-effort
     * @param (callable(string, AudioTranscriptionResult): void)|null $onWorkspaceReady receives the WAV path
     */
    public function transcribeFile(
        string $sourcePath,
        TranscriptionProvider $provider = TranscriptionProvider::Whisper,
        ?callable $onStage = null,
        ?callable $onWorkspaceReady = null,
    ): AudioTranscriptionResult {
        $engine = $this->engines->for($provider);

        // Order matters, and this is the order. Both checks are local configuration only — no process,
        // no socket — so an unrunnable job costs one filesystem stat rather than a conversion.
        $this->normalizer->assertAvailable();
        $engine->assertReady();

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
            $this->normalizer->normalise($sourcePath, $wav);

            if ($onStage !== null) {
                $onStage('TRANSCRIBING');
            }

            // Keyterms are deployment configuration today. A later feature will derive them from
            // repeated manual corrections and fill the same object — which is why the engine takes a
            // request rather than a path, and why nothing here branches on which provider will read it.
            $request = new TranscriptionRequest($wav, $this->keyterms($provider));

            $result = $engine->recognise($request);

            // Speaker separation runs here, while the converted WAV still exists. Diarizing the same
            // file the engine transcribed is what lets the two timelines be aligned without an offset.
            if ($onWorkspaceReady !== null) {
                $onWorkspaceReady($wav, $result);
            }

            return $result;
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /**
     * Only Deepgram has a use for these; Whisper ignores the field entirely.
     *
     * Handing Whisper an empty set rather than asking the engine what it supports keeps the branch here
     * a single expression instead of a capability negotiation, and keeps `TranscriptionRequest` the same
     * shape for every provider.
     */
    private function keyterms(TranscriptionProvider $provider): DeepgramKeyterms
    {
        return $provider === TranscriptionProvider::Deepgram
            ? $this->settings->deepgram->keyterms
            : DeepgramKeyterms::none();
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
