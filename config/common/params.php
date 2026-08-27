<?php

declare(strict_types=1);

use App\Agent\Application\CurrentAgent;
use App\Auth\Application\CurrentAdmin;
use App\Environment;
use App\Shared\ApplicationParams;
use App\Shared\Web\Flash\FlashMessages;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Assets\AssetManager;
use Yiisoft\Definitions\Reference;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\CsrfViewInjection;

// Environment variables are read here and nowhere else. `config/common/di/app-params.php` turns these
// arrays into typed readonly objects, which is all that application services ever see.
return [
    'application' => require __DIR__ . '/application.php',

    // The one application timezone (business/scheduling/display). DB storage stays UTC.
    'app/timezone' => Environment::string('APP_TIMEZONE'),

    'app/auth' => [
        'maxLoginAttempts' => Environment::int('AUTH_MAX_LOGIN_ATTEMPTS'),
        'loginWindowMinutes' => Environment::int('AUTH_LOGIN_WINDOW_MINUTES'),
        'loginLockoutMinutes' => Environment::int('AUTH_LOGIN_LOCKOUT_MINUTES'),
    ],

    'app/db' => [
        'host' => Environment::string('DB_HOST'),
        'port' => Environment::int('DB_PORT'),
        'socket' => Environment::string('DB_SOCKET'),
        'name' => Environment::string('DB_NAME'),
        'user' => Environment::string('DB_USER'),
        'password' => Environment::string('DB_PASSWORD'),
        'charset' => Environment::string('DB_CHARSET'),
    ],

    'app/storage' => [
        'documentRoot' => Environment::string('KNOWLEDGE_STORAGE_PATH'),
        'lockFile' => Environment::string('DOCUMENT_WORKER_LOCK_PATH'),
        'logDirectory' => '@runtime/logs',
    ],

    'app/documents' => [
        'maxUploadBytes' => Environment::int('MAX_UPLOAD_SIZE_MB') * 1024 * 1024,
        'maxImageBytes' => Environment::int('MAX_IMAGE_UPLOAD_SIZE_MB') * 1024 * 1024,
        'maxDocumentsPerKnowledgeBase' => Environment::int('MAX_DOCUMENTS_PER_KNOWLEDGE_BASE'),
        'imageMaxWidth' => Environment::int('IMAGE_MAX_WIDTH'),
        'imageMaxHeight' => Environment::int('IMAGE_MAX_HEIGHT'),
    ],

    'app/worker' => [
        'batchSize' => Environment::int('DOCUMENT_WORKER_BATCH_SIZE'),
        'maxProcessingAttempts' => Environment::int('DOCUMENT_MAX_PROCESSING_ATTEMPTS'),
        'processingTimeoutMinutes' => Environment::int('DOCUMENT_PROCESSING_TIMEOUT_MINUTES'),
        'retryBaseSeconds' => Environment::int('DOCUMENT_RETRY_BASE_SECONDS'),
        'provisionMaxAttempts' => Environment::int('AI_OPERATION_MAX_ATTEMPTS'),
        'indexPollIntervalSeconds' => Environment::int('OPENAI_INDEX_POLL_INTERVAL_SECONDS'),
    ],

    'app/chat' => [
        'model' => Environment::string('OPENAI_CHAT_MODEL'),
        'maxResults' => Environment::int('OPENAI_FILE_SEARCH_MAX_RESULTS'),
        'maxOutputTokens' => Environment::int('CHAT_MAX_OUTPUT_TOKENS'),
        'forceFileSearch' => Environment::bool('CHAT_FORCE_FILE_SEARCH'),
        'requireCitations' => Environment::bool('CHAT_REQUIRE_CITATIONS'),
        'minCitationScore' => Environment::float('CHAT_MIN_CITATION_SCORE'),
        'fallbackMessage' => Environment::string('CHAT_FALLBACK_MESSAGE'),
        'maxQuestionLength' => Environment::int('CHAT_MAX_QUESTION_LENGTH'),
        'historyMessageLimit' => Environment::int('CHAT_HISTORY_MESSAGE_LIMIT'),
        'historyCharLimit' => Environment::int('CHAT_HISTORY_CHAR_LIMIT'),
        'reasoningEffort' => Environment::string('CHAT_REASONING_EFFORT'),
        'exhaustiveMaxResults' => Environment::int('CHAT_EXHAUSTIVE_MAX_RESULTS'),
        'truncatedMessage' => Environment::string('CHAT_TRUNCATED_MESSAGE'),
    ],

    // Audio to Text. Transport only: every default, type and range lives in Environment::SPEC, and
    // nothing here interprets a value. An empty temporaryDirectory means "use @runtime/audio-to-text",
    // which `config/common/di/audio-to-text.php` resolves because an alias needs the container.
    'app/audio-to-text' => [
        'ffmpegBinary' => Environment::string('FFMPEG_BINARY'),
        'temporaryDirectory' => Environment::string('AUDIO_TRANSCRIPTION_TEMP_DIR'),
        'ffprobeBinary' => Environment::string('FFPROBE_BINARY'),
        'whisperBinary' => Environment::string('WHISPER_BINARY'),
        'whisperModel' => Environment::string('WHISPER_MODEL'),
        'maxUploadBytes' => Environment::int('AUDIO_TRANSCRIPTION_MAX_SIZE'),
        'maxDurationSeconds' => Environment::int('AUDIO_TRANSCRIPTION_MAX_DURATION'),
        'timeoutSeconds' => Environment::int('AUDIO_TRANSCRIPTION_TIMEOUT'),
        'threads' => Environment::int('AUDIO_TRANSCRIPTION_THREADS'),
        'maxQueue' => Environment::int('AUDIO_TRANSCRIPTION_MAX_QUEUE'),
        'retentionSeconds' => Environment::int('AUDIO_TRANSCRIPTION_RETENTION_SECONDS'),
        'staleAfterSeconds' => Environment::int('AUDIO_TRANSCRIPTION_STALE_AFTER'),
        'workerSleepSeconds' => Environment::int('AUDIO_TRANSCRIPTION_WORKER_SLEEP'),
    ],

    'app/audio-worker' => [
        'heartbeatSeconds' => Environment::int('AUDIO_WORKER_HEARTBEAT_SECONDS'),
        'staleAfterSeconds' => Environment::int('AUDIO_WORKER_STALE_AFTER'),
        'tickStaleAfterSeconds' => Environment::int('AUDIO_WORKER_TICK_STALE_AFTER'),
        'minAvailableMegabytes' => Environment::int('AUDIO_WORKER_MIN_AVAILABLE_MB'),
        'maxLoadPerCore' => Environment::float('AUDIO_WORKER_MAX_LOAD_PER_CORE'),
        'foreignLocks' => Environment::string('AUDIO_WORKER_FOREIGN_LOCKS'),
        'yieldToOtherWhisper' => Environment::bool('AUDIO_WORKER_YIELD_TO_OTHER_WHISPER'),
    ],

    'app/audio-diarization' => [
        'enabled' => Environment::bool('AUDIO_DIARIZATION_ENABLED'),
        'binary' => Environment::string('AUDIO_DIARIZATION_BINARY'),
        'segmentationModel' => Environment::string('AUDIO_DIARIZATION_SEGMENTATION_MODEL'),
        'embeddingModel' => Environment::string('AUDIO_DIARIZATION_EMBEDDING_MODEL'),
        'timeoutSeconds' => Environment::int('AUDIO_DIARIZATION_TIMEOUT'),
        'minConfidence' => Environment::float('AUDIO_DIARIZATION_MIN_CONFIDENCE'),
        'maxSpeakers' => Environment::int('AUDIO_DIARIZATION_MAX_SPEAKERS'),
        'boundaryToleranceMs' => Environment::int('AUDIO_DIARIZATION_BOUNDARY_TOLERANCE_MS'),
    ],

    'app/pdf' => [
        'minCharsPerPage' => Environment::int('PDF_MIN_TEXT_CHARS_PER_PAGE'),
        'probeMaxBytes' => Environment::int('PDF_TEXT_PROBE_MAX_BYTES'),
        'visionMaxPages' => Environment::int('PDF_VISION_MAX_PAGES'),
        'visionMaxBytes' => Environment::int('PDF_VISION_MAX_BYTES'),
    ],

    'app/openai' => [
        'apiKey' => Environment::string('OPENAI_API_KEY'),
        // Optional. Empty means the organization reporting endpoints are unavailable and the usage
        // dashboard says so, rather than falling back to the project key.
        'adminApiKey' => Environment::string('OPENAI_ADMIN_API_KEY'),
        'baseUrl' => Environment::string('OPENAI_BASE_URL'),
        'chatModel' => Environment::string('OPENAI_CHAT_MODEL'),
        'visionModel' => Environment::string('OPENAI_VISION_MODEL'),
        'profiles' => [
            // Synchronous, inside a web request: must finish well before the web server gives up.
            'chat' => [
                'connectTimeoutSeconds' => Environment::int('OPENAI_CHAT_CONNECT_TIMEOUT_SECONDS'),
                'timeoutSeconds' => Environment::int('OPENAI_CHAT_TIMEOUT_SECONDS'),
                'maxRetries' => Environment::int('OPENAI_CHAT_MAX_RETRIES'),
                'maxBackoffSeconds' => Environment::int('OPENAI_CHAT_RETRY_MAX_BACKOFF_SECONDS'),
            ],
            // Background, from cron: nothing is waiting, so patience is cheap.
            'worker' => [
                'connectTimeoutSeconds' => Environment::int('OPENAI_WORKER_CONNECT_TIMEOUT_SECONDS'),
                'timeoutSeconds' => Environment::int('OPENAI_WORKER_TIMEOUT_SECONDS'),
                'maxRetries' => Environment::int('OPENAI_WORKER_MAX_RETRIES'),
                'maxBackoffSeconds' => Environment::int('OPENAI_WORKER_RETRY_MAX_BACKOFF_SECONDS'),
            ],
            // Admin usage dashboard: a read-only sweep inside a web request, so the budget is the
            // binding constraint. ZERO retries is deliberate — one retry would make a single call cost
            // (20+5)*2+backoff, which alone exceeds the sync's whole time budget. The sync is
            // user-triggered and idempotent, so pressing Sync again is the retry.
            'usage' => [
                'connectTimeoutSeconds' => 5,
                'timeoutSeconds' => 20,
                'maxRetries' => 0,
                'maxBackoffSeconds' => 0,
            ],
        ],
        'fileSearchMaxResults' => Environment::int('OPENAI_FILE_SEARCH_MAX_RESULTS'),
        'indexPollIntervalSeconds' => Environment::int('OPENAI_INDEX_POLL_INTERVAL_SECONDS'),
        'indexPollMaxSeconds' => Environment::int('OPENAI_INDEX_POLL_MAX_SECONDS'),
        'operationMaxAttempts' => Environment::int('AI_OPERATION_MAX_ATTEMPTS'),
    ],

    'app/order58' => [
        'baseUrl' => Environment::string('ORDER58_API_BASE_URL'),
        'token' => Environment::string('ORDER58_API_TOKEN'),
        'connectTimeoutSeconds' => Environment::int('ORDER58_API_CONNECT_TIMEOUT_SECONDS'),
        'timeoutSeconds' => Environment::int('ORDER58_API_TIMEOUT_SECONDS'),
        'maxRetries' => Environment::int('ORDER58_API_MAX_RETRIES'),
        'maxBackoffSeconds' => Environment::int('ORDER58_API_RETRY_MAX_BACKOFF_SECONDS'),
        'pageSize' => Environment::int('ORDER58_API_PAGE_SIZE'),
        'syncMaxAttempts' => Environment::int('ORDER58_SYNC_MAX_ATTEMPTS'),
        'pagesPerRun' => Environment::int('ORDER58_SYNC_PAGES_PER_RUN'),
        'showStoreProfileDocuments' => Environment::bool('ORDER58_SHOW_STORE_PROFILE_DOCUMENTS'),
        // Fallback credential validation (a separate Order58 API; see Order58ValidateCredentials).
        'validateUrl' => Environment::string('ORDER58_VALIDATE_API_URL'),
        'validateToken' => Environment::string('ORDER58_VALIDATE_API_TOKEN'),
        'validateConnectTimeoutSeconds' => Environment::int('ORDER58_VALIDATE_CONNECT_TIMEOUT_SECONDS'),
        'validateTimeoutSeconds' => Environment::int('ORDER58_VALIDATE_TIMEOUT_SECONDS'),
        'validateMaxMirrorAgeHours' => Environment::int('ORDER58_VALIDATE_MAX_MIRROR_AGE_HOURS'),
    ],

    'app/usage' => [
        // Wall clock a single sync may spend calling the provider. Checked before every call, so the
        // worst case is this budget plus one in-flight call (25s) — well inside the web-server timeout.
        'budgetSeconds' => 45,
        'throttleSeconds' => 10,
        'snapshotFile' => '@runtime/cache/openai-usage-dashboard.json',
        // A stable path: locking the per-write temporary file would never make two syncs contend.
        'lockFile' => '@runtime/cache/openai-usage-dashboard.lock',
        'attemptFile' => '@runtime/cache/openai-usage-dashboard.attempt.json',
    ],

    'yiisoft/aliases' => [
        'aliases' => require __DIR__ . '/aliases.php',
    ],

    'yiisoft/view' => [
        'basePath' => null,
        'parameters' => [
            'assetManager' => Reference::to(AssetManager::class),
            'applicationParams' => Reference::to(ApplicationParams::class),
            'aliases' => Reference::to(Aliases::class),
            'urlGenerator' => Reference::to(UrlGeneratorInterface::class),
            'currentRoute' => Reference::to(CurrentRoute::class),
            // Request-scoped services the shared layouts read: who is signed in, and one-shot
            // notifications. Resolved per request, so on a public page currentAdmin is simply empty.
            'currentAdmin' => Reference::to(CurrentAdmin::class),
            'currentAgent' => Reference::to(CurrentAgent::class),
            'flash' => Reference::to(FlashMessages::class),
        ],
    ],

    'yiisoft/yii-view-renderer' => [
        'viewPath' => null,
        'layout' => '@src/Web/Shared/Layout/Main/layout.php',
        'injections' => [
            Reference::to(CsrfViewInjection::class),
        ],
    ],
];
