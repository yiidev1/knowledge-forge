<?php

declare(strict_types=1);

use App\Order58\Client\HttpOrder58Client;
use App\Order58\Client\Order58RetryPolicy;
use App\Order58\Contract\Order58ClientInterface;
use App\Order58\Domain\Order58AgentRepositoryInterface;
use App\Order58\Domain\Order58KnowledgeRepositoryInterface;
use App\Order58\Domain\Order58RuleRepositoryInterface;
use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Order58\Domain\StoreDirectoryReaderInterface;
use App\Order58\Domain\SyncRunRepositoryInterface;
use App\Order58\Infrastructure\DbOrder58AgentRepository;
use App\Order58\Infrastructure\DbOrder58KnowledgeRepository;
use App\Order58\Infrastructure\DbOrder58RuleRepository;
use App\Order58\Infrastructure\DbOrder58StoreRepository;
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
    StoreDirectoryReaderInterface::class => DbStoreDirectoryReader::class,

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
];
