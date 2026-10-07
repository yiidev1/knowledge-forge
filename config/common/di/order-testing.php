<?php

declare(strict_types=1);

use App\OrderTesting\Application\Comparison\OrderComparator;
use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Application\DemoOrderDirectory;
use App\OrderTesting\Application\TestAttemptService;
use App\OrderTesting\Console\ImportDemoOrdersCommand;
use App\OrderTesting\Domain\DemoOrderRepositoryInterface;
use App\OrderTesting\Domain\SourceOrderReaderInterface;
use App\OrderTesting\Domain\StoreReaderInterface;
use App\OrderTesting\Domain\TestAttemptRepositoryInterface;
use App\OrderTesting\Infrastructure\DbDemoOrderRepository;
use App\OrderTesting\Infrastructure\DbSourceOrderReader;
use App\OrderTesting\Infrastructure\DbStoreReader;
use App\OrderTesting\Infrastructure\DbTestAttemptRepository;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Definitions\DynamicReference;

/**
 * Order Testing.
 *
 * Its own file rather than lines inside `order58.php` or `audio-to-text.php`: the feature owns two
 * tables, one console command and three pages, and removing it should be a directory and this file.
 *
 * @var array $params
 */
return [
    OrderNormalizer::class => OrderNormalizer::class,
    OrderComparator::class => OrderComparator::class,

    DemoOrderRepositoryInterface::class => DbDemoOrderRepository::class,
    TestAttemptRepositoryInterface::class => DbTestAttemptRepository::class,
    SourceOrderReaderInterface::class => DbSourceOrderReader::class,
    StoreReaderInterface::class => DbStoreReader::class,

    DemoOrderDirectory::class => [
        '__construct()' => [
            // `@runtime/...` is an alias and cannot be resolved until the container exists, so the
            // value still comes from $params and this closure resolves the alias and nothing else.
            // A configured absolute path is used exactly as given — production points this at
            // /data/orders/demo_mix_orders, and the importer refuses anything inside the LIVE
            // directory regardless of what is configured. See DemoOrderDirectory.
            'configuredPath' => DynamicReference::to(
                static fn(Aliases $aliases): string => $aliases->get(
                    $params['app/order-testing']['demoOrdersDir'],
                ),
            ),
        ],
    ],

    TestAttemptService::class => [
        '__construct()' => [
            'ttlMinutes' => $params['app/order-testing']['attemptTtlMinutes'],
        ],
    ],

    ImportDemoOrdersCommand::class => [
        '__construct()' => [
            // Its OWN lock file. Sharing one with another command is how every run of both ends up
            // skipping — a mistake this project has made before and documented.
            'lockFile' => DynamicReference::to(
                static fn(Aliases $aliases): string => $aliases->get('@runtime') . '/order-testing-import.lock',
            ),
        ],
    ],
];
