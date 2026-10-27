<?php

declare(strict_types=1);

use App\Order58\Client\HttpOrder58Client;
use App\Order58\Client\HttpOrder58CredentialValidator;
use App\Order58\Client\Order58RetryPolicy;
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
    // Case Order call sessions and their WAV links: metadata only, the audio is streamed on demand.
    App\Order58\Domain\CallRecordings\CallRecordingRepositoryInterface::class
        => App\Order58\Infrastructure\DbCallRecordingRepository::class,

    // Streaming a recording when somebody presses Play or Download — the Order API viewer's approach,
    // shared with the order pages. Fixture mode is resolved HERE, against the environment, so it can be
    // true only in dev or test whatever the setting says; production always streams from the provider.
    App\Order58\Application\RecordingStream\RecordingStreamSettings::class => [
        '__construct()' => [
            'maxConcurrent' => (int) $params['app/order58']['recordingStreamMaxConcurrent'],
            'fixture' => $params['app/order58']['recordingStreamSource'] === 'fixture'
                && App\Integration\Order58Recording\FixtureAvailability::isPermitted(),
            'fixtureDirectory' => DynamicReference::to(
                static fn(Aliases $aliases): string => $aliases->get('@root/tests/_data/call-recordings'),
            ),
            'lockDirectory' => DynamicReference::to(
                static fn(Aliases $aliases): string => $aliases->get('@runtime/locks'),
            ),
        ],
    ],
    /*
     * The Case Order endpoint: one request a day, returning recent orders with their lines.
     *
     * Three objects, assembled here because this is where the environment is read: the settings, the
     * HTTP client that uses them, and the run repository that rations the allowance with a unique
     * index on the business day.
     */
    App\Order58\Client\CaseOrders\CaseOrderSettings::class => [
        '__construct()' => [
            'url' => $params['app/order58-case-orders']['url'],
            'token' => $params['app/order58-case-orders']['token'],
            // The provider's own example uses the query string; a bearer header also answered 200.
            // Switchable so a change of provider mind is a setting rather than an edit.
            'auth' => App\Order58\Client\CaseOrders\CaseOrderAuth::fromConfig(
                (string) $params['app/order58-case-orders']['auth'],
            ),
            'timezone' => $params['app/order58-case-orders']['timezone'],
        ],
    ],
    App\Order58\Client\CaseOrders\CaseOrderClientInterface::class => [
        'class' => App\Order58\Client\CaseOrders\HttpCaseOrderClient::class,
        '__construct()' => [
            /*
             * Its own Guzzle instance, like every other Order58 client here — there is no shared
             * PSR-18 binding in this application, by design: each endpoint has its own timeouts and
             * one of them must not be able to change another's.
             *
             * The timeout is the long one. This endpoint returned 1.18 MB for two hundred orders with
             * their lines, so it is a slower call than the per-order one it replaces, and it is made
             * once a day rather than per click. `CaseOrderSettings::TIMEOUT_SECONDS` is where that
             * number is written down.
             *
             * `stream: true` so the body is read in bounded chunks rather than buffered whole: the
             * client enforces a ceiling as it reads, which only works if Guzzle has not already
             * loaded the response into memory.
             *
             * `http_errors: false` so a refusal arrives as a status code the client turns into its own
             * sanitized message, rather than as a Guzzle exception whose text carries the request URI
             * — and in query-string mode that URI carries the credential.
             */
            'httpClient' => DynamicReference::to(static fn(): PsrHttpClient => new GuzzleClient([
                'connect_timeout' => 10,
                'timeout' => App\Order58\Client\CaseOrders\CaseOrderSettings::TIMEOUT_SECONDS,
                'http_errors' => false,
                'stream' => true,
            ])),
            'requestFactory' => DynamicReference::to(static fn(): RequestFactoryInterface => $psr7),
            'streamFactory' => DynamicReference::to(static fn(): StreamFactoryInterface => $psr7),
        ],
    ],
    App\Order58\Domain\CaseOrders\CaseOrderListReaderInterface::class
        => App\Order58\Infrastructure\DbCaseOrderListReader::class,

    App\Order58\Domain\CaseOrders\CaseOrderSyncRunRepositoryInterface::class
        => App\Order58\Infrastructure\DbCaseOrderSyncRunRepository::class,

    // Freshness, the provider's rate and the concurrency lease — three limits, read once, kept apart.
    App\Order58\Application\CaseOrders\CaseOrderSyncPolicy::class => [
        '__construct()' => [
            'autoSyncEnabled' => (bool) $params['app/order58-case-orders']['autoSyncEnabled'],
            'minRefreshSeconds' => (int) $params['app/order58-case-orders']['minRefreshSeconds'],
            'minProviderGapSeconds' => (int) $params['app/order58-case-orders']['minProviderGapSeconds'],
            'leaseSeconds' => (int) $params['app/order58-case-orders']['leaseSeconds'],
        ],
    ],

    /*
     * Manage Orders: which data source the picker opens on.
     *
     * The single place the default is wired. `MANAGE_ORDERS_DEFAULT_SOURCE` in `.env` moves it;
     * the words are normalised in StoreAudioFilter::fromConfiguredDefault(), which also decides what
     * an unrecognised value falls back to. Nothing else in the application reads the setting.
     */
    App\Order58\Web\StoreAudio\OrderTestingAction::class => [
        '__construct()' => [
            'defaultSource' => (string) $params['app/order58-case-orders']['manageOrdersDefaultSource'],
        ],
    ],

    StoreAudioCountsInterface::class => [
        'class' => DbStoreAudioCounts::class,
        '__construct()' => [
            // The same setting the listing reads, so a store card and the page it links to count the
            // same population. See DbStoreAudioCounts and OrderListingMode.
            'caseOrderPrimary' => (bool) $params['app/order58']['caseOrderPrimary'],
        ],
    ],

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

    /*
     * ---------------------------------------------------------------- original order ITEMS
     *
     * The per-order items ENDPOINT is gone (Stage 7a): the Case Order response carries each order's
     * lines inside the order, so there is nothing left to fetch one order at a time. What remains here
     * is local only — the rows, their sync state, and the batch progress the Orders page reads.
     */
    // The progress bar above the orders listing: one grouped count, local tables only.
    ItemSyncSummaryReaderInterface::class => DbItemSyncSummaryReader::class,

    // Per-operation progress for the Orders page. Local only: the batches are settled by whatever
    // writes an order's items, which since Stage 7a is the Case Order import.
    ItemSyncBatchRepositoryInterface::class => DbItemSyncBatchRepository::class,

    OrderItemRepositoryInterface::class => DbOrder58OrderItemRepository::class,
    ItemSyncStateRepositoryInterface::class => DbItemSyncStateRepository::class,

];
