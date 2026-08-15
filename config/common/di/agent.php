<?php

declare(strict_types=1);

use App\Agent\Application\FallbackAgentAuthenticator;
use App\Agent\Domain\AgentConversationRepositoryInterface;
use App\Agent\Domain\AgentLoginActivityRepositoryInterface;
use App\Agent\Domain\AgentStoreDirectoryInterface;
use App\Agent\Domain\TrustedAgentDirectoryInterface;
use App\Agent\Infrastructure\DbAgentConversationRepository;
use App\Agent\Infrastructure\DbAgentLoginActivityRepository;
use App\Agent\Infrastructure\DbAgentStoreDirectory;
use App\Agent\Infrastructure\DbTrustedAgentDirectory;

/** @var array $params */

// Only the repository interfaces need binding; the login service, middleware, CurrentAgent and the web
// actions are autowired from their constructor types. The identity store is web-only (config/web/di).
// FallbackAgentAuthenticator needs one scalar, so it is the exception that carries a definition.
return [
    AgentConversationRepositoryInterface::class => DbAgentConversationRepository::class,
    AgentStoreDirectoryInterface::class => DbAgentStoreDirectory::class,
    AgentLoginActivityRepositoryInterface::class => DbAgentLoginActivityRepository::class,
    TrustedAgentDirectoryInterface::class => DbTrustedAgentDirectory::class,

    FallbackAgentAuthenticator::class => [
        '__construct()' => [
            'maxMirrorAgeHours' => $params['app/order58']['validateMaxMirrorAgeHours'],
        ],
    ],
];
