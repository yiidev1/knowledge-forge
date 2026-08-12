<?php

declare(strict_types=1);

use App\Agent\Domain\AgentConversationRepositoryInterface;
use App\Agent\Domain\AgentLoginActivityRepositoryInterface;
use App\Agent\Domain\AgentStoreDirectoryInterface;
use App\Agent\Infrastructure\DbAgentConversationRepository;
use App\Agent\Infrastructure\DbAgentLoginActivityRepository;
use App\Agent\Infrastructure\DbAgentStoreDirectory;

// Only the repository interfaces need binding; the login service, middleware, CurrentAgent and the web
// actions are autowired from their constructor types. The identity store is web-only (config/web/di).
return [
    AgentConversationRepositoryInterface::class => DbAgentConversationRepository::class,
    AgentStoreDirectoryInterface::class => DbAgentStoreDirectory::class,
    AgentLoginActivityRepositoryInterface::class => DbAgentLoginActivityRepository::class,
];
