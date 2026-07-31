<?php

declare(strict_types=1);

use App\Ai\OpenAi\OpenAiAdminCredentials;
use App\Ai\OpenAi\OpenAiCredentials;
use App\Ai\OpenAi\OpenAiHttpProfile;
use App\Order58\Application\Order58SyncParams;
use App\Order58\Client\Order58Credentials;
use App\Order58\Client\Order58HttpProfile;
use App\Shared\Application\Correlation\CorrelationId;
use App\Shared\Application\Health\HealthChecker;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DbParams;
use App\Shared\Infrastructure\Log\SafeLogContext;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Shared\Infrastructure\Storage\StoragePaths;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;

/** @var array $params */

// Turns the raw arrays from `config/common/params.php` into typed readonly objects. This file is the
// only boundary where a string key becomes a constructor argument; everything downstream is typed.
return [
    ClockInterface::class => SystemClock::class,

    CorrelationId::class => static fn(): CorrelationId => new CorrelationId(),

    DbParams::class => [
        '__construct()' => [
            'host' => $params['app/db']['host'],
            'port' => $params['app/db']['port'],
            'socket' => $params['app/db']['socket'],
            'name' => $params['app/db']['name'],
            'user' => $params['app/db']['user'],
            'password' => $params['app/db']['password'],
            'charset' => $params['app/db']['charset'],
        ],
    ],

    // Aliases are resolved here so nothing downstream has to know about them, and so a relative path
    // can never be resolved against whatever working directory cron happens to run in.
    StoragePaths::class => static fn(Aliases $aliases): StoragePaths => new StoragePaths(
        documentRoot: $aliases->get($params['app/storage']['documentRoot']),
        lockFile: $aliases->get($params['app/storage']['lockFile']),
        logDirectory: $aliases->get($params['app/storage']['logDirectory']),
    ),

    OpenAiCredentials::class => [
        '__construct()' => [
            'apiKey' => $params['app/openai']['apiKey'],
            'baseUrl' => $params['app/openai']['baseUrl'],
            'chatModel' => $params['app/openai']['chatModel'],
            'visionModel' => $params['app/openai']['visionModel'],
        ],
    ],

    // Two profiles under distinct service ids. Phase 5 injects `ai.profile.chat` into the chat provider
    // and `ai.profile.worker` into the indexing and extraction adapters.
    'ai.profile.chat' => [
        'class' => OpenAiHttpProfile::class,
        '__construct()' => [
            'name' => 'chat',
            'connectTimeoutSeconds' => $params['app/openai']['profiles']['chat']['connectTimeoutSeconds'],
            'timeoutSeconds' => $params['app/openai']['profiles']['chat']['timeoutSeconds'],
            'maxRetries' => $params['app/openai']['profiles']['chat']['maxRetries'],
            'maxBackoffSeconds' => $params['app/openai']['profiles']['chat']['maxBackoffSeconds'],
        ],
    ],

    'ai.profile.worker' => [
        'class' => OpenAiHttpProfile::class,
        '__construct()' => [
            'name' => 'worker',
            'connectTimeoutSeconds' => $params['app/openai']['profiles']['worker']['connectTimeoutSeconds'],
            'timeoutSeconds' => $params['app/openai']['profiles']['worker']['timeoutSeconds'],
            'maxRetries' => $params['app/openai']['profiles']['worker']['maxRetries'],
            'maxBackoffSeconds' => $params['app/openai']['profiles']['worker']['maxBackoffSeconds'],
        ],
    ],

    // Read-only inventory sweep for the admin usage dashboard. Zero retries: one retry would make a
    // single call cost (20+5)*2 seconds, which alone exceeds the sync's whole time budget.
    'ai.profile.usage' => [
        'class' => OpenAiHttpProfile::class,
        '__construct()' => [
            'name' => 'usage',
            'connectTimeoutSeconds' => $params['app/openai']['profiles']['usage']['connectTimeoutSeconds'],
            'timeoutSeconds' => $params['app/openai']['profiles']['usage']['timeoutSeconds'],
            'maxRetries' => $params['app/openai']['profiles']['usage']['maxRetries'],
            'maxBackoffSeconds' => $params['app/openai']['profiles']['usage']['maxBackoffSeconds'],
        ],
    ],

    // Optional organization credential. Held apart from OpenAiCredentials so the project key can never
    // be promoted into an admin key, and so "not configured" stays a first-class, non-fatal state.
    OpenAiAdminCredentials::class => [
        '__construct()' => [
            'apiKey' => $params['app/openai']['adminApiKey'],
            'baseUrl' => $params['app/openai']['baseUrl'],
        ],
    ],

    Order58Credentials::class => [
        '__construct()' => [
            'token' => $params['app/order58']['token'],
            'baseUrl' => $params['app/order58']['baseUrl'],
        ],
    ],

    // A single HTTP profile: all Order58 traffic runs in the cron worker, so it can be patient.
    'order58.profile' => [
        'class' => Order58HttpProfile::class,
        '__construct()' => [
            'name' => 'order58',
            'connectTimeoutSeconds' => $params['app/order58']['connectTimeoutSeconds'],
            'timeoutSeconds' => $params['app/order58']['timeoutSeconds'],
            'maxRetries' => $params['app/order58']['maxRetries'],
            'maxBackoffSeconds' => $params['app/order58']['maxBackoffSeconds'],
        ],
    ],

    Order58SyncParams::class => [
        '__construct()' => [
            'pageSize' => $params['app/order58']['pageSize'],
            'maxAttempts' => $params['app/order58']['syncMaxAttempts'],
            'pagesPerRun' => $params['app/order58']['pagesPerRun'],
        ],
    ],

    // Seeded with the live credentials so that even a provider error body echoing the request back
    // cannot carry them into a log file.
    SecretRedactor::class => [
        '__construct()' => [
            'literals' => [
                $params['app/openai']['apiKey'],
                // Usually empty; the redactor ignores literals shorter than 6 characters, so an unset
                // admin key costs nothing. Leaving it out would mean the one key most likely to appear
                // in an organization-endpoint error body is the one that is not scrubbed.
                $params['app/openai']['adminApiKey'],
                $params['app/db']['password'],
                // The Order58 Integration API Bearer token, so an echoed request or a transport error
                // that quotes the Authorization header can never carry it into a log.
                $params['app/order58']['token'],
            ],
        ],
    ],

    SafeLogContext::class => SafeLogContext::class,

    // Everything not named here is autowired by type: DbParams, DbConnectionFactory, StoragePaths and
    // OpenAiCredentials all resolve from the definitions above.
    HealthChecker::class => [
        '__construct()' => [
            // Of the two profiles, the health check reports on the chat one, because that is the only
            // budget constrained by an external deadline (the web server's).
            'chatProfile' => Reference::to('ai.profile.chat'),
            'rootPath' => DynamicReference::to(static fn(Aliases $aliases): string => $aliases->get('@root')),
            'migrationsPath' => DynamicReference::to(
                static fn(Aliases $aliases): string => $aliases->get('@src/Migration'),
            ),
            // Must track the `fastcgi_read_timeout` in docs/nginx/knowledge-forge.conf. The check warns
            // when the synchronous chat budget could outlive it — a mismatch that reaches the user as
            // an unexplained 504 rather than as an error page.
            //
            // 120s, not 90s: with the default 45s timeout and one retry the pessimistic bound is 102s.
            // Phase 5 will additionally enforce a wall-clock deadline inside the retry policy, so the
            // bound is guaranteed in code rather than merely checked here.
            'webServerTimeoutSeconds' => 120,
        ],
    ],
];
