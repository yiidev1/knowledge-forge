<?php

declare(strict_types=1);

namespace App\Agent\Domain;

/**
 * The set of stores available to agents right now: active, enabled for agent use, provisioned (vector
 * store ready), and holding at least one indexed document — the same list for every active agent. This is
 * the single authorization gate for agent chat; `account_id` is never consulted.
 */
interface AgentStoreDirectoryInterface
{
    /**
     * @return list<AgentStore>
     */
    public function findAvailable(): array;

    public function findAvailableBySlug(string $slug): ?AgentStore;

    /**
     * How many stores are available to agents right now — the same predicate as {@see findAvailable}, for
     * the admin "stores available to agents" metric.
     */
    public function countAvailable(): int;
}
