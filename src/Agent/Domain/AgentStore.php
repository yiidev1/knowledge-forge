<?php

declare(strict_types=1);

namespace App\Agent\Domain;

use function array_filter;
use function implode;
use function trim;

/**
 * A store an agent may chat with — the projection the agent store picker needs. Every active agent sees the
 * same list (there is no per-agent assignment); `account_id` plays no part in it. Location fields come from
 * the mirrored store snapshot and are null for a manually-created knowledge base with no source store.
 */
final readonly class AgentStore
{
    public function __construct(
        public int $knowledgeBaseId,
        public string $slug,
        public string $name,
        public ?string $company = null,
        public ?string $address = null,
        public ?string $city = null,
        public ?string $state = null,
        public int $documentCount = 0,
    ) {}

    /**
     * A single-line "City, State" (or whichever is present), or null when neither is known.
     */
    public function location(): ?string
    {
        $parts = array_filter([
            $this->city === null ? '' : trim($this->city),
            $this->state === null ? '' : trim($this->state),
        ], static fn(string $part): bool => $part !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }
}
