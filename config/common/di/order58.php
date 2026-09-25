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
use App\Order58\Domain\AudioProviderDefaultInterface;
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Order58\Domain\StoreAudioCountsInterface;
use App\Order58\Domain\StoreDirectoryReaderInterface;
use App\Order58\Domain\SyncRunRepositoryInterface;
use App\Order58\Infrastructure\DbOrder58AgentRepository;
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
    StoreAudioCountsInterface::class => DbStoreAudioCounts::class,
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
];
