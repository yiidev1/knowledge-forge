<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\Settings\DeepgramSettings;
use App\AudioToText\Application\Settings\DiarizationSettings;
use App\AudioToText\Application\Settings\TranscriptionSettings;
use App\AudioToText\Application\Settings\TtsSettings;
use App\AudioToText\Application\Settings\WorkerSettings;
use App\AudioToText\Domain\Transcription\DeepgramKeyterms;
use App\AudioToText\Domain\Tts\TtsOutputFormat;
use App\Shared\Domain\ValueObject\SecretValue;

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
        // Deepgram. Unconfigured by default — an empty key — because that is the state of a machine
        // that has not opted in, and every existing test is about the Whisper path. A test that wants
        // Deepgram usable passes a key; nothing here ever reaches the network either way.
        string $deepgramApiKey = '',
        string $deepgramBaseUrl = 'https://api.deepgram.com/v1/listen',
        string $deepgramModel = 'nova-3',
        string $deepgramLanguage = 'multi',
        bool $deepgramSmartFormat = true,
        // Off by default, as Deepgram and this project both default it — so a test that does not care
        // about numerals produces the request every other test already expects.
        bool $deepgramNumerals = false,
        int $deepgramTimeoutSeconds = 120,
        /** @var list<string> */
        array $deepgramKeyterms = [],
        // Text-to-speech. Unconfigured by default for the same reason Deepgram above is: an empty key is
        // the state of a machine that has not opted in, and no existing test is about AI audio. A test
        // that wants it usable passes a key. Nothing here reaches the network either way — the
        // synthesizer is an interface and every test substitutes a fake.
        string $ttsApiKey = '',
        string $ttsUrl = 'https://api.deepgram.com/v1/speak',
        string $ttsCustomerModel = 'aura-2-thalia-en',
        string $ttsAgentModel = 'aura-2-arcas-en',
        // One voice per side, for a recording that holds only one. Distinct from the pair above so a
        // test can tell which of the four a script actually selected.
        string $ttsCallerModel = 'aura-2-andromeda-en',
        string $ttsCalleeModel = 'aura-2-orpheus-en',
        int $ttsSampleRate = 24000,
        int $ttsMaxCharactersPerRequest = 1900,
        int $ttsTimeoutSeconds = 120,
        int $ttsGapMilliseconds = 350,
        TtsOutputFormat $ttsOutputFormat = TtsOutputFormat::Mp3,
        int $ttsMaxAttempts = 3,
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
            new DeepgramSettings(
                apiKey: new SecretValue($deepgramApiKey),
                baseUrl: $deepgramBaseUrl,
                model: $deepgramModel,
                language: $deepgramLanguage,
                smartFormat: $deepgramSmartFormat,
                numerals: $deepgramNumerals,
                timeoutSeconds: $deepgramTimeoutSeconds,
                keyterms: DeepgramKeyterms::fromList($deepgramKeyterms),
            ),
            new TtsSettings(
                apiKey: new SecretValue($ttsApiKey),
                url: $ttsUrl,
                customerModel: $ttsCustomerModel,
                agentModel: $ttsAgentModel,
                callerModel: $ttsCallerModel,
                calleeModel: $ttsCalleeModel,
                sampleRate: $ttsSampleRate,
                maxCharactersPerRequest: $ttsMaxCharactersPerRequest,
                timeoutSeconds: $ttsTimeoutSeconds,
                gapMilliseconds: $ttsGapMilliseconds,
                outputFormat: $ttsOutputFormat,
                maxAttempts: $ttsMaxAttempts,
            ),
        );
    }
}
