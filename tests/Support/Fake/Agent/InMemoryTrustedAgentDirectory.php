<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Agent;

use App\Agent\Domain\AgentIdentity;
use App\Agent\Domain\TrustedAgentDirectoryInterface;
use App\Agent\Domain\TrustedAgentLookupResult;
use DateTimeImmutable;

/**
 * A programmable trusted-agent directory. Records the freshness cut-off it was given so a test can assert
 * the staleness window is actually derived from the configured age, not hardcoded.
 */
final class InMemoryTrustedAgentDirectory implements TrustedAgentDirectoryInterface
{
    public TrustedAgentLookupResult $result;

    /** Usernames the directory was asked about, in order. */
    public array $lookups = [];

    public ?DateTimeImmutable $lastNotSyncedBefore = null;

    public function __construct()
    {
        $this->result = TrustedAgentLookupResult::notFound();
    }

    public function findActiveAgentByUsername(
        string $username,
        DateTimeImmutable $notSyncedBefore,
    ): TrustedAgentLookupResult {
        $this->lookups[] = $username;
        $this->lastNotSyncedBefore = $notSyncedBefore;

        return $this->result;
    }

    public function willReturn(TrustedAgentLookupResult $result): void
    {
        $this->result = $result;
    }

    public function willFind(int $adminId = 139, string $username = 'agent'): AgentIdentity
    {
        $identity = new AgentIdentity($adminId, $username, 'Agent One', 'agent@test.com', 'active', 'agent');
        $this->result = TrustedAgentLookupResult::found($identity);

        return $identity;
    }
}
