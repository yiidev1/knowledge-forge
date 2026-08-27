<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\Settings\DiarizationSettings;
use App\AudioToText\Application\Settings\TranscriptionSettings;
use App\AudioToText\Application\Settings\WorkerSettings;

/**
 * Builds the one Audio-to-Text settings object for tests.
 *
 * Every value is a named argument with an explicit default, so a test states only what it actually
 * cares about and never depends on whatever this machine's `.env` happens to say. It also means a new
 * setting is added in one place here rather than in every test that constructs settings.
 */
final class AudioToTextSettingsFactory
{
    public static function create(
        // Transcription
        string $ffmpegBinary = '/usr/bin/ffmpeg',
        string $ffprobeBinary = '/usr/bin/ffprobe',
        string $whisperBinary = '/opt/whisper.cpp/build/bin/whisper-cli',
        string $whisperModel = '/opt/whisper.cpp/models/ggml-small.bin',
        string $temporaryDirectory = '/tmp/audio-to-text-test',
        int $maxUploadBytes = 31457280,
        int $maxDurationSeconds = 300,
        int $timeoutSeconds = 600,
        int $threads = 1,
        int $maxQueue = 0,
        int $retentionSeconds = 0,
        int $staleAfterSeconds = 1200,
        int $workerSleepSeconds = 2,
        // Worker
        int $heartbeatSeconds = 5,
        int $workerStaleAfterSeconds = 30,
        int $tickStaleAfterSeconds = 180,
        int $minAvailableMegabytes = 1500,
        float $maxLoadPerCore = 1.5,
        string $foreignLocks = '',
        bool $yieldToOtherWhisper = true,
        // Diarization
        bool $diarizationEnabled = false,
        string $diarizationBinary = '/opt/audio-diarization/venv/bin/python3',
        string $segmentationModel = '/opt/audio-diarization/models/segmentation.onnx',
        string $embeddingModel = '/opt/audio-diarization/models/embedding.onnx',
        int $diarizationTimeoutSeconds = 300,
        float $minConfidence = 0.55,
        int $maxSpeakers = 2,
        int $boundaryToleranceMs = 1500,
    ): AudioToTextSettings {
        return new AudioToTextSettings(
            new TranscriptionSettings(
                ffmpegBinary: $ffmpegBinary,
                ffprobeBinary: $ffprobeBinary,
                whisperBinary: $whisperBinary,
                whisperModel: $whisperModel,
                temporaryDirectory: $temporaryDirectory,
                maxUploadBytes: $maxUploadBytes,
                maxDurationSeconds: $maxDurationSeconds,
                timeoutSeconds: $timeoutSeconds,
                threads: $threads,
                maxQueue: $maxQueue,
                retentionSeconds: $retentionSeconds,
                staleAfterSeconds: $staleAfterSeconds,
                workerSleepSeconds: $workerSleepSeconds,
            ),
            new WorkerSettings(
                heartbeatSeconds: $heartbeatSeconds,
                staleAfterSeconds: $workerStaleAfterSeconds,
                tickStaleAfterSeconds: $tickStaleAfterSeconds,
                minAvailableMegabytes: $minAvailableMegabytes,
                maxLoadPerCore: $maxLoadPerCore,
                foreignLocks: $foreignLocks,
                yieldToOtherWhisper: $yieldToOtherWhisper,
            ),
            new DiarizationSettings(
                enabled: $diarizationEnabled,
                binary: $diarizationBinary,
                segmentationModel: $segmentationModel,
                embeddingModel: $embeddingModel,
                timeoutSeconds: $diarizationTimeoutSeconds,
                minConfidence: $minConfidence,
                maxSpeakers: $maxSpeakers,
                boundaryToleranceMs: $boundaryToleranceMs,
            ),
        );
    }
}
