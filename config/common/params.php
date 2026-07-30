<?php

declare(strict_types=1);

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
