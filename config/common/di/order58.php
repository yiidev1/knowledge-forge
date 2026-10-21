<?php

declare(strict_types=1);

use App\Order58\Client\HttpOrder58Client;
use App\Order58\Client\HttpOrder58CredentialValidator;
use App\Order58\Application\Items\OrderItemSyncInterface;
use App\Order58\Application\Items\OriginalOrderItemSyncService;
use App\Order58\Application\Orders\OrderSyncLockInterface;
use App\Order58\Application\Orders\OrderSyncService;
use App\Order58\Client\Order58RetryPolicy;
use App\Order58\Client\Orders\HttpOrderDataClient;
use App\Order58\Client\Items\HttpOrderItemClient;
use App\Order58\Client\Items\OrderItemClientInterface;
use App\Order58\Client\Items\OrderItemSettings;
use App\Order58\Client\Orders\OrderDataClientInterface;
use App\Order58\Client\Orders\OrderDataSettings;
use App\Order58\Client\Receipt\HttpReceiptClient;
use App\Order58\Client\Receipt\ReceiptClientInterface;
use App\Order58\Client\Receipt\ReceiptSettings;
use App\Order58\Domain\Items\ItemSyncStateRepositoryInterface;
use App\Order58\Domain\Items\ItemSyncBatchRepositoryInterface;
use App\Order58\Domain\Items\ItemSyncSummaryReaderInterface;
use App\Order58\Domain\Items\OrderItemRepositoryInterface;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use App\Order58\Infrastructure\DbItemSyncBatchRepository;
use App\Order58\Infrastructure\DbItemSyncSummaryReader;
use App\Order58\Infrastructure\DbOrder58OrderRepository;
use App\Order58\Contract\Order58ClientInterface;
use App\Order58\Contract\Order58CredentialValidatorInterface;
use App\Order58\Domain\Order58AgentRepositoryInterface;
use App\Order58\Domain\Order58KnowledgeRepositoryInterface;
use App\Order58\Domain\Order58RuleRepositoryInterface;
use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Order58\Domain\AudioProviderDefaultInterface;
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Order58\Domain\StoreAudioCountsInterface;
use App\Order58\Domain\StoreDirectoryReaderInterface;
use App\Order58\Domain\SyncRunRepositoryInterface;
use App\Order58\Infrastructure\DbOrder58AgentRepository;
use App\Order58\Infrastructure\DbItemSyncStateRepository;
use App\Order58\Infrastructure\DbOrder58OrderItemRepository;
use App\Order58\Infrastructure\DbOrderSyncLock;
use App\Order58\Infrastructure\DbOrder58KnowledgeRepository;
use App\Order58\Infrastructure\DbOrder58RuleRepository;
use App\Order58\Infrastructure\DbOrder58StoreRepository;
use App\Order58\Infrastructure\DbAudioProviderDefault;
use App\Order58\Infrastructure\DbCallImportRepository;
use App\Order58\Infrastructure\DbStoreAudioCounts;
use App\Order58\Infrastructure\DbStoreDirectoryReader;
use App\Order58\Infrastructure\DbSyncRunRepository;
use App\Shared\Machine\ResourceBudget;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface as PsrHttpClient;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Aliases\Aliases;
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
    CallImportRepositoryInterface::class => DbCallImportRepository::class,
    Order58OrderRepositoryInterface::class => DbOrder58OrderRepository::class,
    StoreAudioCountsInterface::class => DbStoreAudioCounts::class,

    // Orders carrying a topic, per store, for the second number on a store card.
    App\Order58\Domain\StoreTopicAssignmentCountsInterface::class
        => App\Order58\Infrastructure\DbStoreTopicAssignmentCounts::class,
    AudioProviderDefaultInterface::class => DbAudioProviderDefault::class,

    // The recording importer. The lock file is its own — sharing the transcription worker's would make
    // the two needlessly exclusive, and sharing a cron wrapper's would make every run skip.
    //
    // Its resource budget is its own too, and much smaller than the transcription worker's. A tick was
    // measured at 44 MB, about 92 MB while ffprobe runs, so the default 350 MB is roughly 3.8x its peak.
    // Handing it the transcription threshold would make a 92 MB download wait for a gigabyte, which on a
    // small server means waiting for ever — the guard fails closed by design.
    App\Order58\Console\ImportRecordingsCommand::class => [
        '__construct()' => [
            'budget' => Reference::to('order58.import.budget'),
            'lockFile' => DynamicReference::to(
                static fn(Aliases $aliases): string => $aliases->get('@runtime/locks') . '/order58-import.lock',
            ),
            // The same call the downloader makes, in the same process, so the startup check and the actual
            // download can never disagree about which directory is in use. Steered by TMPDIR in the
            // systemd unit so the temporary file shares a filesystem with its destination.
            'temporaryDirectory' => DynamicReference::to(static fn(): string => sys_get_temp_dir()),
            'enabled' => $params['app/order58']['recordingImportEnabled'],
            // Item work rides along with this command rather than needing a worker of its own. The
            // bounds keep it strictly subordinate to the recordings: see drainItems().
            // The concrete drainer, named here because the command asks only for a DrainerInterface.
            'itemDrainer' => Reference::to(App\Order58\Application\Items\OrderItemSyncDrainer::class),
            'itemDrainPerRun' => $params['app/order58-items']['drainPerRun'],
            'itemBudgetSeconds' => $params['app/order58-items']['drainBudgetSeconds'],
            'itemRunCeilingSeconds' => $params['app/order58-items']['runCeilingSeconds'],
            'itemRequestCostSeconds' => $params['app/order58-items']['requestCostSeconds'],
        ],
    ],

    'order58.import.budget' => [
        'class' => ResourceBudget::class,
        '__construct()' => [
            'minAvailableMegabytes' => $params['app/order58']['recordingImportMinAvailableMb'],
            'maxLoadPerCore' => $params['app/order58']['recordingImportMaxLoadPerCore'],
        ],
    ],

    // The page and its Sync action both read the feature flag: the page to say so, the action to
    // enforce it. A form rendered before the flag was turned off must not still queue work.
    App\Order58\Web\Calls\Action::class => [
        '__construct()' => ['importEnabled' => $params['app/order58']['recordingImportEnabled']],
    ],
    App\Order58\Web\Calls\SyncAction::class => [
        '__construct()' => ['importEnabled' => $params['app/order58']['recordingImportEnabled']],
    ],

    // The recordings page reads the same flag for the same two reasons. One switch governs every path
    // that reaches the provider, so turning it off cannot leave one page still asking.
    App\Order58\Web\CallRecordings\Action::class => [
        '__construct()' => ['importEnabled' => $params['app/order58']['recordingImportEnabled']],
    ],
    // One small file in `runtime/`, watched by a systemd `.path` unit that starts the importer service.
    // Beside the lock rather than anywhere else: the service already declares `runtime` as writable, so
    // the trigger needs no new path, no new permission and no server change beyond installing the unit.
    App\Order58\Application\ImportRunRequest::class => [
        '__construct()' => [
            'triggerFile' => DynamicReference::to(
                static fn(Aliases $aliases): string => $aliases->get('@runtime/triggers') . '/order58-import.trigger',
            ),
        ],
    ],

    App\Order58\Web\CallRecordings\DownloadAction::class => [
        '__construct()' => ['importEnabled' => $params['app/order58']['recordingImportEnabled']],
    ],
    // The status, history and retry endpoints read and write rows that already exist; none of them can
    // reach the provider, so the import flag does not gate them. A server with downloading turned off
    // must still be able to show what it downloaded before.

    App\Order58\Application\RecordingImportProcessor::class => [
        '__construct()' => [
            'maxAttempts' => $params['app/order58']['recordingImportMaxAttempts'],
        ],
    ],

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

    // The Orders API: a separate host, verb and credential from the accounts client above, and the only
    // Order58 call that runs inside a web request. Its timeouts are therefore its own and are kept under
    // the web server's, so a slow provider ends the call rather than the request.
    OrderDataSettings::class => DynamicReference::to(static fn(): OrderDataSettings => new OrderDataSettings(
        url: $params['app/order58']['ordersUrl'],
        token: $params['app/order58']['ordersToken'],
        connectTimeoutSeconds: $params['app/order58']['ordersConnectTimeoutSeconds'],
        timeoutSeconds: $params['app/order58']['ordersTimeoutSeconds'],
        maxResponseBytes: $params['app/order58']['ordersMaxResponseMb'] * 1048576,
    )),

    OrderDataClientInterface::class => [
        'class' => HttpOrderDataClient::class,
        '__construct()' => [
            'httpClient' => DynamicReference::to(static fn(): PsrHttpClient => new GuzzleClient([
                'connect_timeout' => $params['app/order58']['ordersConnectTimeoutSeconds'],
                'timeout' => $params['app/order58']['ordersTimeoutSeconds'],
                'http_errors' => false,
                // Read in bounded chunks rather than letting Guzzle buffer a response of unknown size.
                'stream' => true,
            ])),
            'requestFactory' => DynamicReference::to(static fn(): RequestFactoryInterface => $psr7),
            'streamFactory' => DynamicReference::to(static fn(): StreamFactoryInterface => $psr7),
        ],
    ],

    // The Receipt endpoint of that same order-data API, wired exactly like the Orders client beside it
    // and for the same reasons: a POST from inside a web request, so its timeouts are its own and are
    // kept under the web server's. Its credential is separate so it can be rotated on its own.
    ReceiptSettings::class => DynamicReference::to(static fn(): ReceiptSettings => new ReceiptSettings(
        url: $params['app/order58']['receiptUrl'],
        token: $params['app/order58']['receiptToken'],
        connectTimeoutSeconds: $params['app/order58']['receiptConnectTimeoutSeconds'],
        timeoutSeconds: $params['app/order58']['receiptTimeoutSeconds'],
        maxResponseBytes: $params['app/order58']['receiptMaxResponseMb'] * 1048576,
    )),

    ReceiptClientInterface::class => [
        'class' => HttpReceiptClient::class,
        '__construct()' => [
            'httpClient' => DynamicReference::to(static fn(): PsrHttpClient => new GuzzleClient([
                'connect_timeout' => $params['app/order58']['receiptConnectTimeoutSeconds'],
                'timeout' => $params['app/order58']['receiptTimeoutSeconds'],
                'http_errors' => false,
                // Read in bounded chunks rather than letting Guzzle buffer a response of unknown size.
                'stream' => true,
                // TLS verification stays ON. Named here rather than left to the default so that
                // turning it off becomes a visible edit to this file instead of a quiet one.
                'verify' => true,
                // One hop is followed by the client itself, in code we can bound; Guzzle must not
                // follow a chain of its own and carry the Authorization header to a host we never
                // chose.
                'allow_redirects' => false,
            ])),
            'requestFactory' => DynamicReference::to(static fn(): RequestFactoryInterface => $psr7),
            'streamFactory' => DynamicReference::to(static fn(): StreamFactoryInterface => $psr7),
        ],
    ],

    // ---------------------------------------------------------------- original order ITEMS (Phase 2)
    //
    // A fourth Order58 endpoint with its own credential and its own kill switch. SHIPS OFF:
    // ORDER58_ITEM_SYNC_ENABLED defaults to false, and the client refuses before opening a socket, so
    // wiring it up sends no traffic.
    OrderItemSettings::class => DynamicReference::to(static fn(): OrderItemSettings => new OrderItemSettings(
        url: $params['app/order58-items']['url'],
        token: $params['app/order58-items']['token'],
        authScheme: $params['app/order58-items']['authScheme'],
        connectTimeoutSeconds: $params['app/order58-items']['connectTimeoutSeconds'],
        timeoutSeconds: $params['app/order58-items']['timeoutSeconds'],
        maxResponseBytes: $params['app/order58-items']['maxResponseBytes'],
        enabled: $params['app/order58-items']['enabled'],
    )),

    OrderItemClientInterface::class => [
        'class' => HttpOrderItemClient::class,
        '__construct()' => [
            // Its own Guzzle instance, like the orders client: a shorter timeout, because a manual
            // click waits on this inside a web request.
            'httpClient' => DynamicReference::to(static fn(): PsrHttpClient => new GuzzleClient([
                'connect_timeout' => $params['app/order58-items']['connectTimeoutSeconds'],
                'timeout' => $params['app/order58-items']['timeoutSeconds'],
                'http_errors' => false,
                // Read in bounded chunks rather than letting Guzzle buffer a response of unknown size.
                'stream' => true,
            ])),
            'requestFactory' => DynamicReference::to(static fn(): RequestFactoryInterface => $psr7),
            'streamFactory' => DynamicReference::to(static fn(): StreamFactoryInterface => $psr7),
        ],
    ],

    // The progress bar above the orders listing: one grouped count, local tables only.
    ItemSyncSummaryReaderInterface::class => DbItemSyncSummaryReader::class,

    // Per-operation progress. Settled from OriginalOrderItemSyncService, so one provider
    // request settles every batch waiting on that order.
    ItemSyncBatchRepositoryInterface::class => DbItemSyncBatchRepository::class,

    App\Order58\Web\Orders\SyncStatusAction::class => [
        '__construct()' => [
            'stalledAfterSeconds' => $params['app/order58-items']['batchStalledSeconds'],
        ],
    ],

    OrderItemRepositoryInterface::class => DbOrder58OrderItemRepository::class,
    ItemSyncStateRepositoryInterface::class => DbItemSyncStateRepository::class,

    // The drainer and both manual actions depend on the interface, so none of them names the concrete
    // service — and the service stays final.
    OrderItemSyncInterface::class => OriginalOrderItemSyncService::class,

    OriginalOrderItemSyncService::class => [
        '__construct()' => [
            'leaseSeconds' => $params['app/order58-items']['leaseSeconds'],
            'maxAttempts' => $params['app/order58-items']['maxAttempts'],
        ],
    ],

    // The order-sync lock, which lives in the service so BOTH callers are covered: the Orders page
    // button and kf:order58:import-recordings. It used to be two lines in the web action, leaving the
    // recording path unprotected.
    OrderSyncLockInterface::class => DbOrderSyncLock::class,

    OrderSyncService::class => [
        'class' => OrderSyncService::class,
        '__construct()' => [
            'deadlineSeconds' => $params['app/order58']['ordersDeadlineSeconds'],
            // Shared with the item sync service so "due a retry" means one thing in both.
            'itemMaxAttempts' => $params['app/order58-items']['maxAttempts'],
        ],
    ],
];
