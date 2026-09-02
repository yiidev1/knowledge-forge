<?php

declare(strict_types=1);

use App\Order58\Client\HttpOrder58Client;
use App\Order58\Client\HttpOrder58CredentialValidator;
use App\Order58\Client\Order58RetryPolicy;
use App\Order58\Contract\Order58ClientInterface;
use App\Order58\Contract\Order58CredentialValidatorInterface;
use App\Order58\Domain\Order58AgentRepositoryInterface;
use App\Order58\Domain\Order58KnowledgeRepositoryInterface;
use App\Order58\Domain\Order58RuleRepositoryInterface;
use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Order58\Domain\StoreAudioCountsInterface;
use App\Order58\Domain\StoreDirectoryReaderInterface;
use App\Order58\Domain\SyncRunRepositoryInterface;
use App\Order58\Infrastructure\DbOrder58AgentRepository;
use App\Order58\Infrastructure\DbOrder58KnowledgeRepository;
use App\Order58\Infrastructure\DbOrder58RuleRepository;
use App\Order58\Infrastructure\DbOrder58StoreRepository;
use App\Order58\Infrastructure\DbStoreAudioCounts;
use App\Order58\Infrastructure\DbStoreDirectoryReader;
use App\Order58\Infrastructure\DbSyncRunRepository;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface as PsrHttpClient;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;

/** @var array $params */

// Guzzle as the PSR-18 transport, with the Order58 timeouts. `http_errors => false` so PSR-18 returns a
// response for every status and only a genuine transport failure throws — the error mapper classifies
// the rest.
$psr7 = new HttpFactory();

return [
    // Repositories.
    Order58StoreRepositoryInterface::class => DbOrder58StoreRepository::class,
    Order58AgentRepositoryInterface::class => DbOrder58AgentRepository::class,
    Order58KnowledgeRepositoryInterface::class => DbOrder58KnowledgeRepository::class,
    Order58RuleRepositoryInterface::class => DbOrder58RuleRepository::class,
    SyncRunRepositoryInterface::class => DbSyncRunRepository::class,
    App\Order58\Domain\DailySyncScheduleRepositoryInterface::class => App\Order58\Infrastructure\DbDailySyncScheduleRepository::class,
    StoreDirectoryReaderInterface::class => DbStoreDirectoryReader::class,
    StoreAudioCountsInterface::class => DbStoreAudioCounts::class,

    Order58RetryPolicy::class => [
        '__construct()' => ['profile' => Reference::to('order58.profile')],
    ],

    Order58ClientInterface::class => [
        'class' => HttpOrder58Client::class,
        '__construct()' => [
            'httpClient' => DynamicReference::to(static fn(): PsrHttpClient => new GuzzleClient([
                'connect_timeout' => $params['app/order58']['connectTimeoutSeconds'],
                'timeout' => $params['app/order58']['timeoutSeconds'],
                'http_errors' => false,
            ])),
            'requestFactory' => DynamicReference::to(static fn(): RequestFactoryInterface => $psr7),
            'streamFactory' => DynamicReference::to(static fn(): StreamFactoryInterface => $psr7),
            'profile' => Reference::to('order58.profile'),
            'retryPolicy' => Reference::to(Order58RetryPolicy::class),
        ],
    ],

    // The fallback credential validator gets its own Guzzle instance: it runs inside a web request, after a
    // primary call has already failed, so it uses far shorter timeouts than the patient sync client above.
    // No retry policy — a login form is waiting, and a resubmitted password is not ours to multiply.
    Order58CredentialValidatorInterface::class => [
        'class' => HttpOrder58CredentialValidator::class,
        '__construct()' => [
            'httpClient' => DynamicReference::to(static fn(): PsrHttpClient => new GuzzleClient([
                'connect_timeout' => $params['app/order58']['validateConnectTimeoutSeconds'],
                'timeout' => $params['app/order58']['validateTimeoutSeconds'],
                'http_errors' => false,
            ])),
            'requestFactory' => DynamicReference::to(static fn(): RequestFactoryInterface => $psr7),
            'streamFactory' => DynamicReference::to(static fn(): StreamFactoryInterface => $psr7),
        ],
    ],
];
