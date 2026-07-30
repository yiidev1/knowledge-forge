<?php

declare(strict_types=1);

use App\Ai\Application\Operation\AiOperationReconciler;
use App\Ai\Application\Usage\CollectUsageSnapshotService;
use App\Ai\Application\Usage\SyncAttemptMarkerInterface;
use App\Ai\Application\Usage\UsageParams;
use App\Ai\Application\Usage\UsageSnapshotStoreInterface;
use App\Ai\Infrastructure\Usage\FileSyncAttemptMarker;
use App\Ai\Infrastructure\Usage\FileUsageSnapshotStore;
use App\Ai\Contract\ChatCompletionProviderInterface;
use App\Ai\Contract\DocumentContentExtractorInterface;
use App\Ai\Contract\KnowledgeIndexInterface;
use App\Ai\Domain\AiOperationRepositoryInterface;
use App\Ai\Infrastructure\DbAiOperationRepository;
use App\Ai\OpenAi\Adapter\OpenAiChatCompletionProvider;
use App\Ai\OpenAi\Adapter\OpenAiDocumentContentExtractor;
use App\Ai\OpenAi\Adapter\OpenAiKnowledgeIndex;
use App\Ai\OpenAi\Client\HttpOpenAiClient;
use App\Ai\OpenAi\Client\OpenAiClientInterface;
use App\Ai\OpenAi\Client\RetryPolicy;
use App\Ai\OpenAi\Client\SleeperInterface;
use App\Ai\OpenAi\Client\UsleepSleeper;
use App\Ai\OpenAi\OpenAiAdminCredentials;
use App\Ai\OpenAi\OpenAiParams;
use App\Ai\OpenAi\Reconciliation\VectorStoreCreateReconciliation;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface as PsrHttpClient;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;

/** @var array $params */

// Markdown extraction wants far more output than a chat turn (a whole page of Markdown), so it has its
// own generous cap rather than borrowing the chat limit.
$extractionMaxOutputTokens = 8000;

/**
 * Builds a Guzzle PSR-18 client with the timeouts for a given profile. Two of these exist, so the chat
 * tier can stay impatient while the worker is patient.
 */
$httpClientFor = static function (array $profile): GuzzleClient {
    return new GuzzleClient([
        'connect_timeout' => $profile['connectTimeoutSeconds'],
        'timeout' => $profile['timeoutSeconds'],
        // PSR-18: return responses for all HTTP statuses; only transport failures throw.
        'http_errors' => false,
    ]);
};

$psr7 = new HttpFactory();

return [
    // Shared, stateless collaborators.
    SleeperInterface::class => UsleepSleeper::class,

    OpenAiParams::class => [
        '__construct()' => [
            'fileSearchMaxResults' => $params['app/openai']['fileSearchMaxResults'],
            'indexPollIntervalSeconds' => $params['app/openai']['indexPollIntervalSeconds'],
            'indexPollMaxSeconds' => $params['app/openai']['indexPollMaxSeconds'],
            'operationMaxAttempts' => $params['app/openai']['operationMaxAttempts'],
        ],
    ],

    // ---- Two client profiles ------------------------------------------------
    // Each HttpOpenAiClient pairs a timeout-configured Guzzle client with the matching retry budget.

    'ai.retry.chat' => [
        'class' => RetryPolicy::class,
        '__construct()' => ['profile' => Reference::to('ai.profile.chat')],
    ],
    'ai.retry.worker' => [
        'class' => RetryPolicy::class,
        '__construct()' => ['profile' => Reference::to('ai.profile.worker')],
    ],

    'ai.client.chat' => [
        'class' => HttpOpenAiClient::class,
        '__construct()' => [
            'httpClient' => DynamicReference::to(static fn(): PsrHttpClient => $httpClientFor($params['app/openai']['profiles']['chat'])),
            'requestFactory' => DynamicReference::to(static fn(): RequestFactoryInterface => $psr7),
            'streamFactory' => DynamicReference::to(static fn(): StreamFactoryInterface => $psr7),
            'profile' => Reference::to('ai.profile.chat'),
            'retryPolicy' => Reference::to('ai.retry.chat'),
        ],
    ],
    'ai.client.worker' => [
        'class' => HttpOpenAiClient::class,
        '__construct()' => [
            'httpClient' => DynamicReference::to(static fn(): PsrHttpClient => $httpClientFor($params['app/openai']['profiles']['worker'])),
            'requestFactory' => DynamicReference::to(static fn(): RequestFactoryInterface => $psr7),
            'streamFactory' => DynamicReference::to(static fn(): StreamFactoryInterface => $psr7),
            'profile' => Reference::to('ai.profile.worker'),
            'retryPolicy' => Reference::to('ai.retry.worker'),
        ],
    ],

    'ai.retry.usage' => [
        'class' => RetryPolicy::class,
        '__construct()' => ['profile' => Reference::to('ai.profile.usage')],
    ],

    // Read-only client for the admin usage dashboard. It uses the ordinary project credentials — the
    // inventory endpoints need no special scope — but its own short, retry-free profile, so a sweep can
    // never outlast the web request that started it.
    'ai.client.usage' => [
        'class' => HttpOpenAiClient::class,
        '__construct()' => [
            'httpClient' => DynamicReference::to(static fn(): PsrHttpClient => $httpClientFor($params['app/openai']['profiles']['usage'])),
            'requestFactory' => DynamicReference::to(static fn(): RequestFactoryInterface => $psr7),
            'streamFactory' => DynamicReference::to(static fn(): StreamFactoryInterface => $psr7),
            'profile' => Reference::to('ai.profile.usage'),
            'retryPolicy' => Reference::to('ai.retry.usage'),
        ],
    ],

    // The default OpenAiClientInterface is the worker client (used by ingestion). Chat gets the chat
    // client explicitly, below.
    OpenAiClientInterface::class => Reference::to('ai.client.worker'),

    // ---- Admin usage dashboard (read-only) ---------------------------------

    UsageParams::class => [
        '__construct()' => [
            'budgetSeconds' => $params['app/usage']['budgetSeconds'],
            'throttleSeconds' => $params['app/usage']['throttleSeconds'],
            // Only WHETHER an organization key exists ever leaves the container. The key itself stays
            // inside OpenAiAdminCredentials and is never handed to application or view code.
            'adminApiConfigured' => DynamicReference::to(
                static fn(OpenAiAdminCredentials $credentials): bool => $credentials->isConfigured(),
            ),
        ],
    ],

    UsageSnapshotStoreInterface::class => [
        'class' => FileUsageSnapshotStore::class,
        '__construct()' => [
            'path' => DynamicReference::to(
                static fn(Aliases $aliases): string => $aliases->get($params['app/usage']['snapshotFile']),
            ),
            'lockPath' => DynamicReference::to(
                static fn(Aliases $aliases): string => $aliases->get($params['app/usage']['lockFile']),
            ),
        ],
    ],

    SyncAttemptMarkerInterface::class => [
        'class' => FileSyncAttemptMarker::class,
        '__construct()' => [
            'path' => DynamicReference::to(
                static fn(Aliases $aliases): string => $aliases->get($params['app/usage']['attemptFile']),
            ),
        ],
    ],

    CollectUsageSnapshotService::class => [
        '__construct()' => ['client' => Reference::to('ai.client.usage')],
    ],

    // ---- Contract ports → adapters -----------------------------------------

    ChatCompletionProviderInterface::class => [
        'class' => OpenAiChatCompletionProvider::class,
        '__construct()' => ['client' => Reference::to('ai.client.chat')],
    ],
    KnowledgeIndexInterface::class => [
        'class' => OpenAiKnowledgeIndex::class,
        '__construct()' => ['client' => Reference::to('ai.client.worker')],
    ],
    DocumentContentExtractorInterface::class => [
        'class' => OpenAiDocumentContentExtractor::class,
        '__construct()' => [
            'client' => Reference::to('ai.client.worker'),
            'maxOutputTokens' => $extractionMaxOutputTokens,
        ],
    ],

    // ---- Operation ledger + reconciliation ---------------------------------

    AiOperationRepositoryInterface::class => DbAiOperationRepository::class,

    VectorStoreCreateReconciliation::class => [
        '__construct()' => ['client' => Reference::to('ai.client.worker')],
    ],

    AiOperationReconciler::class => [
        '__construct()' => [
            // Phase 6 adds file-upload and attach strategies to this list.
            'strategies' => [Reference::to(VectorStoreCreateReconciliation::class)],
            'maxAttempts' => $params['app/openai']['operationMaxAttempts'],
        ],
    ],
];
