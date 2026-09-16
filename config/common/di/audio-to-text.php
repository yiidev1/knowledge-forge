<?php

declare(strict_types=1);

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\Settings\DeepgramSettings;
use App\AudioToText\Application\Settings\DiarizationSettings;
use App\AudioToText\Application\Settings\TranscriptionSettings;
use App\AudioToText\Application\Settings\WorkerSettings;
use App\AudioToText\Application\TranscriberResolver;
use App\AudioToText\Domain\AudioToTextSettingsRepositoryInterface;
use App\AudioToText\Domain\Speaker\SpeakerDiarizerInterface;
use App\AudioToText\Domain\SystemResourceProbeInterface;
use App\AudioToText\Domain\SegmentRevisionRepositoryInterface;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\AudioStoreLookupInterface;
use App\AudioToText\Domain\Transcription\DeepgramKeyterms;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Domain\WorkerHeartbeatRepositoryInterface;
use App\AudioToText\Infrastructure\DbAudioToTextSettingsRepository;
use App\AudioToText\Infrastructure\DbSegmentRevisionRepository;
use App\AudioToText\Infrastructure\DbAudioConversationRepository;
use App\AudioToText\Infrastructure\DbAudioStoreLookup;
use App\AudioToText\Infrastructure\DbTranscriptionJobRepository;
use App\AudioToText\Infrastructure\DbWorkerHeartbeatRepository;
use App\AudioToText\Infrastructure\Diarization\NullSpeakerDiarizer;
use App\AudioToText\Infrastructure\Diarization\SherpaOnnxSpeakerDiarizer;
use App\AudioToText\Infrastructure\ProcSystemResourceProbe;
use App\AudioToText\Infrastructure\Transcription\DeepgramEngine;
use App\AudioToText\Infrastructure\Transcription\WhisperEngine;
use App\Shared\Domain\ValueObject\SecretValue;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface as PsrHttpClient;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;

/**
 * Audio-to-Text wiring — assembly only.
 *
 * **This file holds no configuration of its own.** It reads `$params` and assembles the one settings
 * object; it does not define a default, does not read an environment variable, and does not interpret
 * a value. To change a setting, edit `.env` (see {@see AudioToTextSettings} for the full explanation).
 *
 * A new file rather than an edit to an existing one: `config/configuration.php` globs
 * `common/di/*.php`, so adding the feature required no change to any config file that already existed,
 * and removing it is a file deletion.
 *
 * @var array $params
 */

return [
    TranscriptionJobRepositoryInterface::class => DbTranscriptionJobRepository::class,
    AudioConversationRepositoryInterface::class => DbAudioConversationRepository::class,
    AudioStoreLookupInterface::class => DbAudioStoreLookup::class,
    SegmentRevisionRepositoryInterface::class => DbSegmentRevisionRepository::class,
    WorkerHeartbeatRepositoryInterface::class => DbWorkerHeartbeatRepository::class,
    SystemResourceProbeInterface::class => ProcSystemResourceProbe::class,

    TranscriptionSettings::class => [
        '__construct()' => [
            'ffmpegBinary' => $params['app/audio-to-text']['ffmpegBinary'],
            'ffprobeBinary' => $params['app/audio-to-text']['ffprobeBinary'],
            'whisperBinary' => $params['app/audio-to-text']['whisperBinary'],
            'whisperModel' => $params['app/audio-to-text']['whisperModel'],
            // The only value needing more than a copy, and only because `@runtime` is an alias that
            // cannot be resolved until the container exists. The *value* still comes from `$params`;
            // this closure resolves the alias and nothing else. Chosen over an `uploads/` directory
            // because runtime is outside the web root, so a queued recording is never reachable over
            // HTTP even for the seconds it exists.
            'temporaryDirectory' => DynamicReference::to(
                static fn(Aliases $aliases): string => $params['app/audio-to-text']['temporaryDirectory'] !== ''
                    ? $params['app/audio-to-text']['temporaryDirectory']
                    : $aliases->get('@runtime/audio-to-text'),
            ),
            'maxUploadBytes' => $params['app/audio-to-text']['maxUploadBytes'],
            'maxDurationSeconds' => $params['app/audio-to-text']['maxDurationSeconds'],
            'timeoutSeconds' => $params['app/audio-to-text']['timeoutSeconds'],
            'threads' => $params['app/audio-to-text']['threads'],
            'maxQueue' => $params['app/audio-to-text']['maxQueue'],
            'retentionSeconds' => $params['app/audio-to-text']['retentionSeconds'],
            'staleAfterSeconds' => $params['app/audio-to-text']['staleAfterSeconds'],
            'workerSleepSeconds' => $params['app/audio-to-text']['workerSleepSeconds'],
        ],
    ],

    WorkerSettings::class => [
        '__construct()' => [
            'heartbeatSeconds' => $params['app/audio-worker']['heartbeatSeconds'],
            'staleAfterSeconds' => $params['app/audio-worker']['staleAfterSeconds'],
            'tickStaleAfterSeconds' => $params['app/audio-worker']['tickStaleAfterSeconds'],
            'minAvailableMegabytes' => $params['app/audio-worker']['minAvailableMegabytes'],
            'maxLoadPerCore' => $params['app/audio-worker']['maxLoadPerCore'],
            'foreignLocks' => $params['app/audio-worker']['foreignLocks'],
            'yieldToOtherWhisper' => $params['app/audio-worker']['yieldToOtherWhisper'],
        ],
    ],

    DiarizationSettings::class => [
        '__construct()' => [
            'enabled' => $params['app/audio-diarization']['enabled'],
            'binary' => $params['app/audio-diarization']['binary'],
            'segmentationModel' => $params['app/audio-diarization']['segmentationModel'],
            'embeddingModel' => $params['app/audio-diarization']['embeddingModel'],
            'timeoutSeconds' => $params['app/audio-diarization']['timeoutSeconds'],
            'minConfidence' => $params['app/audio-diarization']['minConfidence'],
            'maxSpeakers' => $params['app/audio-diarization']['maxSpeakers'],
            'boundaryToleranceMs' => $params['app/audio-diarization']['boundaryToleranceMs'],
        ],
    ],

    DeepgramSettings::class => [
        '__construct()' => [
            // Wrapped the moment it leaves configuration, and revealed exactly once — in the
            // Authorization header. A SecretValue cannot be printed, serialised or var_dump'd back
            // into a log by accident.
            'apiKey' => DynamicReference::to(
                static fn(): SecretValue => new SecretValue($params['app/audio-deepgram']['apiKey']),
            ),
            'baseUrl' => $params['app/audio-deepgram']['baseUrl'],
            'model' => $params['app/audio-deepgram']['model'],
            'language' => $params['app/audio-deepgram']['language'],
            'smartFormat' => $params['app/audio-deepgram']['smartFormat'],
            'numerals' => $params['app/audio-deepgram']['numerals'],
            'timeoutSeconds' => $params['app/audio-deepgram']['timeoutSeconds'],
            // Splitting the configured string is assembly, which is this file's job — `params.php`
            // carries values, it does not interpret them. `fromList` does the rest: trim, drop empties,
            // de-duplicate.
            'keyterms' => DynamicReference::to(
                static fn(): DeepgramKeyterms => DeepgramKeyterms::fromList(
                    explode(',', $params['app/audio-deepgram']['keyterms']),
                ),
            ),
        ],
    ],

    // The one type every Audio-to-Text service injects. The four groups above exist for readability;
    // nothing outside this file depends on them individually, which is what stops a new setting from
    // rippling through constructors across the module.
    AudioToTextSettings::class => [
        '__construct()' => [
            'transcription' => Reference::to(TranscriptionSettings::class),
            'worker' => Reference::to(WorkerSettings::class),
            'diarization' => Reference::to(DiarizationSettings::class),
            'deepgram' => Reference::to(DeepgramSettings::class),
        ],
    ],

    AudioToTextSettingsRepositoryInterface::class => DbAudioToTextSettingsRepository::class,

    // Guzzle as the PSR-18 transport, following `order58.php`. `http_errors => false` so PSR-18 returns
    // a response for every status and only a genuine transport failure throws — the engine maps a
    // refusal and a transport failure to different exceptions, and needs to be able to tell them apart.
    //
    // Its own client instance, with the Deepgram timeout: this uploads an audio file and waits for a
    // transcription, which is nothing like the request profile of any other client in the application.
    DeepgramEngine::class => [
        '__construct()' => [
            'httpClient' => DynamicReference::to(static fn(): PsrHttpClient => new GuzzleClient([
                'connect_timeout' => 10,
                'timeout' => $params['app/audio-deepgram']['timeoutSeconds'],
                'http_errors' => false,
            ])),
            'requestFactory' => DynamicReference::to(static fn(): RequestFactoryInterface => new HttpFactory()),
            'streamFactory' => DynamicReference::to(static fn(): StreamFactoryInterface => new HttpFactory()),
        ],
    ],

    // Every case of TranscriptionProvider must appear here. `TranscriberResolver` throws rather than
    // falling back if one is missing, and a unit test asserts each case resolves — a silent fallback
    // would transcribe with the wrong engine and record it as the right one.
    TranscriberResolver::class => static fn(
        WhisperEngine $whisper,
        DeepgramEngine $deepgram,
    ): TranscriberResolver => TranscriberResolver::of($whisper, $deepgram),

    // Resolved at build time from configuration, so the "diarization is off" path is a real object with
    // the same interface rather than a flag checked in five places. Selecting the null implementation
    // when the toolchain is absent means a machine without the models still runs every job to
    // completion, reporting NOT_SUPPORTED for the split.
    //
    // Dependencies are declared as closure parameters and autowired, following `db.php`, rather than
    // pulling them from the container by hand — which also keeps this file free of a service locator.
    SpeakerDiarizerInterface::class => static fn(
        AudioToTextSettings $settings,
        SherpaOnnxSpeakerDiarizer $sherpa,
        NullSpeakerDiarizer $disabled,
    ): SpeakerDiarizerInterface => $settings->diarization->enabled && $sherpa->isAvailable()
        ? $sherpa
        : $disabled,
];
