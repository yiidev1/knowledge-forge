<?php

declare(strict_types=1);

use App\Document\Application\Processing\DocumentProcessingDrainer;
use App\Document\Application\RemoteCleanupDrainer;
use App\KnowledgeBase\Application\KnowledgeBaseProvisioningDrainer;
use App\Order58\Application\Sync\IntegrationSyncDrainer;
use App\Worker\Application\WorkerLockInterface;
use App\Worker\Application\WorkerParams;
use App\Worker\Application\WorkerRunner;
use App\Worker\Infrastructure\FlockWorkerLock;
use Yiisoft\Definitions\Reference;

/** @var array $params */

// The worker's tunables and the single-holder lock, plus the runner's ordered drainer list. The
// individual drainers and services are autowired from their constructor types.
return [
    WorkerLockInterface::class => FlockWorkerLock::class,

    WorkerParams::class => [
        '__construct()' => [
            'batchSize' => $params['app/worker']['batchSize'],
            'maxProcessingAttempts' => $params['app/worker']['maxProcessingAttempts'],
            'processingTimeoutMinutes' => $params['app/worker']['processingTimeoutMinutes'],
            'retryBaseSeconds' => $params['app/worker']['retryBaseSeconds'],
            'provisionMaxAttempts' => $params['app/worker']['provisionMaxAttempts'],
            'indexPollIntervalSeconds' => $params['app/worker']['indexPollIntervalSeconds'],
        ],
    ],

    // Order matters: sync Order58 first (a new/changed store creates or updates its knowledge base and
    // queues its generated documents), then provision that knowledge base's vector store, then process its
    // documents, then sweep the remote files left by deletes and re-indexes — all reachable in one pass.
    WorkerRunner::class => [
        '__construct()' => [
            'drainers' => [
                Reference::to(IntegrationSyncDrainer::class),
                Reference::to(KnowledgeBaseProvisioningDrainer::class),
                Reference::to(DocumentProcessingDrainer::class),
                Reference::to(RemoteCleanupDrainer::class),
            ],
        ],
    ],
];
