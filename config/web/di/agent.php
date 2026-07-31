<?php

declare(strict_types=1);

use App\Agent\Domain\AgentIdentityStoreInterface;
use App\Agent\Infrastructure\SessionAgentIdentityStore;

// The agent identity store is web-only because it needs the PSR session, exactly like the admin one.
return [
    AgentIdentityStoreInterface::class => SessionAgentIdentityStore::class,
];
