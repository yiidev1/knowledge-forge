<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Agent;

use App\Agent\Domain\AgentStore;
use App\Agent\Domain\AgentStoreDirectoryInterface;

use function array_values;
use function count;

/**
 * In-memory agent store directory for tests: the stores an agent is allowed to reach, keyed by slug.
 */
final class InMemoryAgentStoreDirectory implements AgentStoreDirectoryInterface
{
    /** @var array<string, AgentStore> */
    private array $bySlug = [];

    public function add(AgentStore $store): void
    {
        $this->bySlug[$store->slug] = $store;
    }

    public function findAvailable(): array
    {
        return array_values($this->bySlug);
    }

    public function findAvailableBySlug(string $slug): ?AgentStore
    {
        return $this->bySlug[$slug] ?? null;
    }

    public function countAvailable(): int
    {
        return count($this->bySlug);
    }
}
