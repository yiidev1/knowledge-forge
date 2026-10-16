<?php

declare(strict_types=1);

use App\OrderTesting\Application\Comparison\ComparisonScorer;
use App\OrderTesting\Application\Playback\PlaybackTracker;
use App\OrderTesting\Domain\Playback\PlaybackRepositoryInterface;
use App\OrderTesting\Infrastructure\DbPlaybackRepository;
use App\Shared\Audio\RecordingIdentityReader;
use App\OrderTesting\Application\Comparison\OrderComparator;
use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Application\DemoOrderDirectory;
use App\OrderTesting\Application\TestAttemptService;
use App\OrderTesting\Application\DemoOrderSyncService;
use App\OrderTesting\Domain\DemoOrderRepositoryInterface;
use App\OrderTesting\Domain\Comparison\OriginalItemsReaderInterface;
use App\OrderTesting\Domain\SourceOrderReaderInterface;
use App\OrderTesting\Domain\StoreReaderInterface;
use App\OrderTesting\Domain\TestAttemptRepositoryInterface;
use App\OrderTesting\Infrastructure\DbDemoOrderRepository;
use App\OrderTesting\Infrastructure\DbOriginalItemsReader;
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

    // Reads one comparison and weighs it. Stateless and persisted nowhere: a score is derived from two
    // documents that both change, so it is computed per render rather than stored and kept in step.
    ComparisonScorer::class => ComparisonScorer::class,

    DemoOrderRepositoryInterface::class => DbDemoOrderRepository::class,
    TestAttemptRepositoryInterface::class => DbTestAttemptRepository::class,
    SourceOrderReaderInterface::class => DbSourceOrderReader::class,

    // The original order's line items and their synchronisation state. Separate from the order reader
    // because "mirrored" and "items known" are different states; see OriginalItemsReaderInterface.
    OriginalItemsReaderInterface::class => DbOriginalItemsReader::class,
    StoreReaderInterface::class => DbStoreReader::class,

    // What agents listened to. One repository behind one port, read by the two realms that manage
    // orders and written only by the agent surface — see the migration for why the two tables are
    // separate and why a dropdown change has no session.
    PlaybackRepositoryInterface::class => DbPlaybackRepository::class,
    PlaybackTracker::class => PlaybackTracker::class,

    // Which store, order and channel a recording belongs to. Shared, because the agent action and the
    // reporting both need it and neither may name the audio module.
    RecordingIdentityReader::class => RecordingIdentityReader::class,

    // Whether a source order has been checked over, and the history of who changed that. Two tables,
    // one repository, written in one transaction — see the migration for why the history is separate.
    App\OrderTesting\Domain\OrderVerificationRepositoryInterface::class
        => App\OrderTesting\Infrastructure\DbOrderVerificationRepository::class,
    App\OrderTesting\Domain\SourceOrderExistenceInterface::class
        => App\OrderTesting\Infrastructure\DbSourceOrderExistence::class,

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

    DemoOrderSyncService::class => [
        '__construct()' => [
            // ONE lock file, named once, for both callers: the console command and the "Sync demo
            // orders" button. Its OWN file, never shared with another command's — sharing a lock is
            // how every run of both ends up skipping, a mistake this project has made before.
            'lockFile' => DynamicReference::to(
                static fn(Aliases $aliases): string => $aliases->get('@runtime') . '/order-testing-import.lock',
            ),
        ],
    ],

    // Who is acting. The adapter reads the request-scoped actor the authorization middleware
    // established; this module never learns which realm that was.
    App\OrderTesting\Domain\TestActorInterface::class
        => App\OrderTesting\Infrastructure\CurrentActorTestActor::class,
];
