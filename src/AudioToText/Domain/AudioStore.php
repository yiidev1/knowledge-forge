<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * The little this module needs to know about a store.
 *
 * Audio-to-Text may not name the Order58 module — `ModuleIsolationTest` matches on that namespace
 * literally and fails the build — so it carries its own read model of a store, exactly as the agent
 * realm does with `AgentStore`. Four fields, no behaviour, and no way to reach back into the other
 * module.
 *
 * `sourceId` is the Order58 account id mirrored into `order58_stores.source_id`. It is the identifier
 * every other table in this project uses for a store, and the one that appears in audio URLs.
 */
final readonly class AudioStore
{
    public function __construct(
        public int $sourceId,
        public string $name,
        public ?string $company,
        public bool $active,
    ) {}
}
